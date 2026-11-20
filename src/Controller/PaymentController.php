<?php

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use App\Service\CardSender;
use App\Service\CheckoutGatewayInterface;
use App\Service\CheckoutLineItems;
use App\Service\LicenceNumberGenerator;
use App\Service\LicenseService;
use App\Service\MemberFileReader;
use App\Service\MemberRegistrar;
use App\Service\PaymentVerifierInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PaymentController extends AbstractController
{
    /**
     * @throws ApiErrorException
     */
    #[Route("/create-checkout-session", name: "create_checkout_session")]
    public function createCheckoutSession(
        SessionInterface $session,
        CheckoutLineItems $lineItems,
        CheckoutGatewayInterface $checkoutGateway,
    ): Response {
        $orderId = $session->get("orderId");

        $checkoutParams = [
            "payment_method_types" => ["card"],
            "line_items" => $lineItems->fromCart($session->get("cart", [])),
            "mode" => "payment",
            "success_url" => $this->generateUrl("payment_success", [], UrlGeneratorInterface::ABSOLUTE_URL),
            "cancel_url" => $this->generateUrl("payment_cancel", [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        if ($orderId) {
            // Permet de vérifier au retour que le paiement correspond bien à la commande
            $checkoutParams["client_reference_id"] = (string)$orderId;
            $checkoutParams["metadata"] = ["order_id" => (string)$orderId];
        }

        $checkoutSessionId = $checkoutGateway->createSession($checkoutParams);

        $session->set("stripeSessionId", $checkoutSessionId);

        return new JsonResponse(["id" => $checkoutSessionId]);
    }

    /**
     * Retour de Stripe après paiement.
     *
     * 1. vérifie auprès de Stripe que le paiement a bien eu lieu ;
     * 2. enregistre club, adhérents et commande en UNE transaction (rapide, quel que soit le nombre d'adhérents) ;
     * 3. répond à l'utilisateur, puis génère et envoie les cartes après l'envoi de la réponse
     *    (kernel.terminate), sans limite de temps et sans qu'un échec d'envoi n'annule l'inscription.
     *
     * @throws Exception
     */
    #[Route("/paiement-succes", name: "payment_success")]
    public function paymentSuccess(
        SessionInterface $session,
        EntityManagerInterface $entityManager,
        EventDispatcherInterface $dispatcher,
        MemberFileReader $fileReader,
        LicenseService $licenseService,
        LoggerInterface $logger,
        PaymentVerifierInterface $paymentVerifier,
        MemberRegistrar $registrar,
        LicenceNumberGenerator $licenceNumbers,
        CardSender $cardSender,
    ): Response {
        $orderId = $session->get("orderId");
        if (!$orderId) {
            // La session est vidée après un succès : un rechargement de la page ne doit pas provoquer d'erreur.
            if ($session->has("lastPaidOrderId")) {
                return $this->render("payment/success.html.twig");
            }

            return $this->redirectToRoute("app_home");
        }

        $order = $entityManager->getRepository(Order::class)->find($orderId);
        if (!$order) {
            throw new Exception("No order found for id " . $orderId);
        }

        // Rechargement de la page de succès : la commande est déjà traitée, on ne recrée rien
        if ($order->getStatus() === "payee") {
            $session->remove("cart");
            $session->remove("orderId");
            $session->remove("stripeSessionId");

            return $this->render("payment/success.html.twig");
        }

        $paymentVerifier->assertPaid($session->get("stripeSessionId"), $order);

        $cart = $session->get("cart", []);
        $season = $this->getSportSeason();
        $culturalCards = [];
        $nextLicence = $licenceNumbers->nextNumber();

        if (isset($cart["club"]["csvFilePath"])) {
            $clubData = $cart["club"];
            $rows = $this->readMembers($fileReader, $clubData["csvFilePath"]);

            $club = new Club();
            $club->setLogo($clubData["logo"]);
            $club->setName($clubData["club_name"]);
            $club->setEmail($clubData["email"]);
            $club->setPresidentName($clubData["president_name"]);
            $club->setTreasurerName($clubData["treasurer_name"]);
            $club->setAddress($clubData["address"] . ", " . $clubData["address2"]);
            $club->setPostalCode($clubData["zip"]);
            $club->setCity($clubData["city"]);
            $club->setCountry($clubData["country"]);
            $club->setSportSeason($season);

            $lastClub = $entityManager->getRepository(Club::class)->findOneBy([], ["id" => "DESC"]);
            $club->setClubNumber($this->generateUniqueClubNumber($lastClub));

            $entityManager->persist($club);

            foreach ($rows as $row) {
                $registrar->createClubMember($order, $club, $row, LicenceNumberGenerator::format($nextLicence++), $season);
            }

            $order->setClub($club);
            $order->setTotalAmount(50.0 + count($rows) * 5);
        }

        if (isset($cart["club_complement"])) {
            $clubNumber = $cart["club_complement"]["club_number"] ?? null;
            $csvFilePath = $cart["club_complement"]["csvFilePath"] ?? null;
            if (!$clubNumber || !$csvFilePath) {
                throw new Exception("Club number or CSV path missing for complement.");
            }

            $club = $entityManager->getRepository(Club::class)->findOneBy(["clubNumber" => $clubNumber]);
            if (!$club) {
                throw new Exception("Club introuvable pour complément: " . $clubNumber);
            }

            $rows = $this->readMembers($fileReader, $csvFilePath);

            foreach ($rows as $row) {
                $registrar->createClubMember($order, $club, $row, LicenceNumberGenerator::format($nextLicence++), $season);
            }

            $order->setClub($club);
            $order->setTotalAmount(count($rows) * 5);
        }

        if (isset($cart["club_individuel"])) {
            $individual = $cart["club_individuel"];

            $member = new Member();
            $member->setFirstName($individual["first_name"]);
            $member->setLastName($individual["last_name"]);
            $member->setBirthDate($individual["birth_date"]);
            $member->setSex($individual["sex"]);
            $member->setEmail($individual["email"]);
            $member->setCommande($order);
            $member->setSportSeason($season);
            $member->setLicenceNumber(LicenceNumberGenerator::format($nextLicence++));

            $entityManager->persist($member);
        }

        if (isset($cart["cultural_registration"])) {
            $cultural = $cart["cultural_registration"];

            $member = new Member();
            $member->setFirstName($cultural["first_name"]);
            $member->setLastName($cultural["last_name"]);
            $member->setBirthDate($cultural["birth_date"]);
            $member->setSex($cultural["sex"]);
            $member->setEmail($cultural["email"]);
            $member->setCommande($order);
            $entityManager->persist($member);

            // Pas de licence ni de PDF : un simple e-mail de confirmation, envoyé tout de suite.
            $culturalCards[] = ["type" => "atelier-culturel", "email" => $member->getEmail(), "data" => $cultural];
        }

        $order->setStatus("payee");

        // Un seul flush : tout est enregistré ou rien (et le paiement reste identifiable via la commande)
        $entityManager->flush();

        $session->remove("cart");
        $session->remove("orderId");
        $session->remove("stripeSessionId");
        $session->remove("csvFile");
        $session->set("lastPaidOrderId", $orderId);

        // Envoi après la réponse au navigateur. Deux modes (variable LICENCE_CARDS_MODE) :
        //  - inline (défaut) : les cartes en attente partent maintenant ;
        //  - cron : seules les confirmations simples partent ; les cartes sont envoyées par `app:licences:envoyer`.
        // Dans les deux cas l'état de chaque carte est enregistré en base : une interruption ne perd rien.
        $sendNow = $this->getParameter("app.licence_cards_mode") !== "cron";
        if ($sendNow || $culturalCards) {
            // Écouteur à usage unique : il se retire dès qu'il s'exécute (pas de double envoi si le processus est réutilisé).
            $listener = static function (TerminateEvent $event) use (&$listener, $dispatcher, $sendNow, $culturalCards, $cardSender, $licenseService, $logger, $orderId): void {
                $dispatcher->removeListener(KernelEvents::TERMINATE, $listener);
                ignore_user_abort(true);
                set_time_limit(0);

                try {
                    if ($culturalCards) {
                        $licenseService->deliver($culturalCards);
                    }
                    if ($sendNow) {
                        // Même verrou que la commande planifiée (app:licences:envoyer) : une carte ne part jamais deux fois.
                        // Si elle tourne déjà, c'est elle qui enverra ces cartes (elles sont en attente en base).
                        $lock = $cardSender->acquireLock();
                        if ($lock === null) {
                            $logger->info("Commande #$orderId : un envoi de cartes est déjà en cours, il traitera aussi ces cartes.");
                        } else {
                            try {
                                $result = $cardSender->deliver($cardSender->pending(PHP_INT_MAX));
                                $logger->info("Cartes de la commande #$orderId traitées.", $result);
                            } finally {
                                $cardSender->releaseLock($lock);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $logger->error("Erreur lors de l'envoi des cartes de la commande #$orderId.", [
                        "error" => $e->getMessage(),
                    ]);
                }
            };
            $dispatcher->addListener(KernelEvents::TERMINATE, $listener);
        }

        return $this->render("payment/success.html.twig");
    }

    /**
     * @return list<array<string, string>>
     */
    private function readMembers(MemberFileReader $fileReader, string $path): array
    {
        try {
            return $fileReader->read($path, pathinfo($path, PATHINFO_EXTENSION));
        } catch (InvalidArgumentException $e) {
            // Ne devrait pas arriver : le fichier a été validé avant le paiement
            throw new Exception("Fichier d'adhérents illisible après paiement : " . $e->getMessage(), 0, $e);
        }
    }

    private function generateUniqueClubNumber(?Club $lastClub): string
    {
        $lastClubNumber = $lastClub ? (int)substr($lastClub->getClubNumber(), 4) : 0;
        $newClubNumber = $lastClubNumber + 1;
        return sprintf("SHIN%04d", $newClubNumber);
    }

    #[Route("/paiement-annulation", name: "payment_cancel")]
    public function paymentCancel(
        SessionInterface $session,
        EntityManagerInterface $entityManager
    ): Response
    {
        // Récupérer l'ID de la commande depuis la session
        $orderId = $session->get("orderId");

        // Vérification si l'ID de commande est défini
        if (!$orderId) {
            // Logique en cas d'absence de l'ID de commande dans la session
            $this->addFlash("error", "Aucune commande trouvée pour annulation.");
            return $this->redirectToRoute("app_home");
        }

        // Récupération de la commande depuis la base de données
        $order = $entityManager->getRepository(Order::class)->find($orderId);

        // Vérification si la commande existe
        if (!$order) {
            // Logique en cas d'absence de la commande dans la base de données
            $this->addFlash("error", "Commande non trouvée dans la base de données.");
            return $this->redirectToRoute("app_home");
        }

        // Une commande déjà payée ne peut pas être annulée
        if ($order->getStatus() !== "payee") {
            $order->setStatus("annulee");
            $entityManager->flush();
        }

        // Nettoyage de la session
        $session->remove("cart");
        $session->remove("orderId");
        $session->remove("stripeSessionId");
        $session->remove("csvFile");

        // Afficher la page d"annulation
        return $this->render("payment/cancel.html.twig");
    }

    #[Route("/view-pdf", name: "view_pdf")]
    public function viewPdfAsHtml(): Response
    {
        $data = [
            "logo" => "uploads/66dc6a958e62e.jpg",
            "club_name" => "Club Example",
            "numero" => "12345",
            "address" => "123 rue de la Paix",
            "address2" => null,
            "zip" => "75001",
            "city" => "Paris",
            "season" => $this->getSportSeason(),
        ];

        // On rend le template HTML directement
        return $this->render("licenses/club.html.twig", [
            "data" => $data
        ]);
    }

    private function getSportSeason(): string
    {
        $today = new DateTimeImmutable();

        // On récupère l'année et le mois courant
        $year = (int)$today->format("Y");
        $month = (int)$today->format("m");

        if ($month >= 7) {
            // Si on est entre juillet et décembre, la saison est : AAAA-AAAA+1
            $startYear = $year;
            $endYear = $year + 1;
        } else {
            // Si on est entre janvier et juin, la saison est : AAAA-1 - AAAA
            $startYear = $year - 1;
            $endYear = $year;
        }

        return sprintf("%d-%d", $startYear, $endYear);
    }
}
