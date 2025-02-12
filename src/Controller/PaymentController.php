<?php

namespace App\Controller;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PaymentController extends AbstractController
{
    /**
     * @throws ApiErrorException
     */
    #[Route('/create-checkout-session', name: 'create_checkout_session')]
    public function createCheckoutSession(SessionInterface $session): Response
    {
        Stripe::setApiKey($this->getParameter('stripe_secret_key'));

        $cart = $session->get('cart', []);

        $lineItems = [];

        if (isset($cart['club'])) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => 'Inscription d\'un club',
                    ],
                    'unit_amount' => 5000,
                ],
                'quantity' => 1,
            ];

            $memberCount = $cart['club']['members'] ?? 0;

            if ($memberCount > 0) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Inscription d\'un membre',
                        ],
                        'unit_amount' => 500, // Prix par membre
                    ],
                    'quantity' => $memberCount,
                ];
            }
        }

        foreach ($cart as $key => $item) {
            // Gérer les produits classiques
            if ($key !== 'club' && isset($item['product'])) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => $item['product']->getName(),
                        ],
                        'unit_amount' => $item['product']->getPrice() * 100, // Le prix en centimes
                    ],
                    'quantity' => $item['quantity'],
                ];
            }

            // Gérer les inscriptions individuelles
            if (isset($cart['club_individuel'])) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Inscription individuelle',
                        ],
                        'unit_amount' => $item['price'] * 100, // Prix de l'inscription individuelle en centimes
                    ],
                    'quantity' => 1,
                ];
            }

            // Gérer les inscriptions arts culturels
            if (isset($cart['cultural_registration'])) {
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => $cart['cultural_registration']['activity'],
                        ],
                        'unit_amount' => $cart['cultural_registration']['price'] * 100,
                    ],
                    'quantity' => 1,
                ];
            }
        }

        $checkoutSession = StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $this->generateUrl('payment_success', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url' => $this->generateUrl('payment_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $session->set('stripeSessionId', $checkoutSession->id);

        return new JsonResponse(['id' => $checkoutSession->id]);
    }

    /**
     * @throws Exception
     * @throws TransportExceptionInterface
     */
    #[Route('/paiement-succes', name: 'payment_success')]
    public function paymentSuccess(
        SessionInterface $session,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
    ): Response
    {
        // Récupération de l'ID de la command
        $orderId = $session->get('orderId');
        if (!$orderId) {
            throw new Exception('No orderId found in session.');
        }

        // Récupération de la commande depuis la base de données
        $order = $entityManager->getRepository(Order::class)->find($orderId);
        if (!$order) {
            throw new Exception('No order found for id ' . $orderId);
        }

        // Récupération des données du panier
        $cart = $session->get('cart');

        $csvFilePath = $cart['club']['csvFilePath'] ?? null;
        if ($csvFilePath) {
            // Récupération des membres à partir du fichier CSV/Excel
            $extension = pathinfo($csvFilePath, PATHINFO_EXTENSION);
            if ($extension === 'csv') {
                $members = $this->getMembersFromCSV($csvFilePath);
            } else {
                $members = $this->getMembersFromXLSX($csvFilePath);
            }

            // Création d'un nouvel objet Club
            $clubData = $cart['club'];
            $club = new Club();

            $logoFile = $clubData["logo"];
            $club->setLogo($logoFile);

            $club->setName($clubData["club_name"]);
            $club->setLogo($clubData["logo"]);
            $club->setEmail($clubData["email"]);
            $club->setPresidentName($clubData["president_name"]);
            $club->setTreasurerName($clubData["treasurer_name"]);
            $club->setAddress($clubData["address"] . ", " . $clubData["address2"]);
            $club->setPostalCode($clubData["zip"]);
            $club->setCity($clubData["city"]);
            $club->setCountry($clubData["country"]);

            $lastClub = $entityManager->getRepository(Club::class)->findOneBy([], ['id' => 'DESC']);
            $newClubNumber = $this->generateUniqueClubNumber($lastClub);
            $club->setClubNumber($newClubNumber);

            $entityManager->persist($club);
            $clubData["numero"] = $club->getClubNumber();

            $pdfFilePath = $this->generateLicensePdf($clubData, "club");
            $this->sendLicenseEmail($club->getEmail(), $pdfFilePath, [], "club", $mailer);

            $members = array_filter($members, function($row) {
                return array_filter($row);
            });

            // Création des membres à partir du fichier
            foreach ($members as $data) {
                $member = new Member();
                $member->setFirstName($data[0]);
                $member->setLastName($data[1]);
                $member->setSex($data[2]);
                $member->setBirthDate($data[3]);
                $member->setAddress($data[4] ?? null);
                $member->setPostalCode($data[5] ?? null);
                $member->setCity($data[6] ?? null);
                $member->setEmail($data[7] ?? null);
                $member->setPhoneNumber($data[8] ?? null);
                $member->setCountry("France");
                $member->setCommande($order);
                $member->setClub($club);

                // Définir un numéro de licence unique
                $lastMember = $entityManager->getRepository(Member::class)->findOneBy([], ['id' => 'DESC']);
                $newLicenceNumber = $this->generateUniqueLicenceNumber($lastMember);
                $member->setLicenceNumber($newLicenceNumber);

                $entityManager->persist($member);

                // Générer le PDF de la licence
                $memberData = [
                    'membre_prenom' => $member->getFirstName(),
                    'membre_nom' => $member->getLastName(),
                    'membre_date' => $member->getBirthDate(),
                    'membre_sexe' => $member->getSex(),
                    'licence' => $member->getLicenceNumber(),
                    'logo' => $logoFile,
                    'club_name' => $club->getName(),
                    'numero' => $club->getClubNumber(),
                ];
                $pdfFilePath = $this->generateLicensePdf($memberData, "club-membre");

                // Envoi de l'email avec la licence
                if ($member->getEmail()) {
                    $this->sendLicenseEmail($member->getEmail(), $pdfFilePath, [], "club-membre", $mailer);
                }

                $clubData["membre_prenom"] = $member->getFirstName();
                $clubData["membre_nom"] = $member->getLastName();
                $clubData["membre_date"] = $member->getBirthDate();
                $clubData["membre_sexe"] = $member->getSex();
                $clubData["licence"] = $member->getLicenceNumber();
            }

            // Liaison du club à la commande
            $order->setClub($club);

            // Mise à jour du montant total
            $memberCount = (float)$clubData["members"];
            $total = 50.0 + ($memberCount * 5);
            $order->setTotalAmount($total);
        }

        if (isset($cart["club_individuel"])) {
            $member = new Member();
            $member->setFirstName($cart["club_individuel"]["first_name"]);
            $member->setLastName($cart["club_individuel"]["last_name"]);
            $member->setBirthDate($cart["club_individuel"]["birth_date"]);
            $member->setSex($cart["club_individuel"]["sex"]);
            $member->setEmail($cart["club_individuel"]["email"]);
            $member->setCommande($order);

            // Définir un numéro de licence unique
            $lastMember = $entityManager->getRepository(Member::class)->findOneBy([], ['id' => 'DESC']);
            $newLicenceNumber = $this->generateUniqueLicenceNumber($lastMember);
            $member->setLicenceNumber($newLicenceNumber);

            $entityManager->persist($member);

            $cart["club_individuel"]["licence"] = $member->getLicenceNumber();

            $pdfFilePath = $this->generateLicensePdf($cart["club_individuel"], "membre-individuel");
            $this->sendLicenseEmail($member->getEmail(), $pdfFilePath, [], "membre-individuel", $mailer);
        }

        if (isset($cart['cultural_registration'])) {
            $member = new Member();
            $member->setFirstName($cart['cultural_registration']['first_name']);
            $member->setLastName($cart['cultural_registration']['last_name']);
            $member->setBirthDate($cart['cultural_registration']['birth_date']);
            $member->setSex($cart['cultural_registration']['sex']);
            $member->setEmail($cart['cultural_registration']['email']);
            $member->setCommande($order); // Lien avec la commande
            $entityManager->persist($member);

//            // Définir un numéro de licence unique
//            $lastMember = $entityManager->getRepository(Member::class)->findOneBy([], ['id' => 'DESC']);
//            $newLicenceNumber = $this->generateUniqueLicenceNumber($lastMember);
//            $member->setLicenceNumber($newLicenceNumber);
//
//            $entityManager->persist($member);
//
//            $cart["cultural_registration"]["licence"] = $member->getLicenceNumber();
//
//            $pdfFilePath = $this->generateLicensePdf($cart["cultural_registration"], "membre-culturel");
//            $this->sendLicenseEmail($member->getEmail(), $pdfFilePath, [], "membre-culturel", $mailer);

              $this->sendCulturalArtsEmail($member->getEmail(), [], "atelier-culturel", $mailer);
        }

        $order->setStatus("payee");

        $entityManager->flush();

        // Nettoyage de la session
        $session->remove("cart");
        $session->remove("orderId");
        $session->remove("csvFile");

        return $this->render("payment/success.html.twig");
    }

    private function generateUniqueClubNumber(?Club $lastClub): string
    {
        $lastClubNumber = $lastClub ? (int)substr($lastClub->getClubNumber(), 4) : 0;
        $newClubNumber = $lastClubNumber + 1;
        return sprintf('SHIN%04d', $newClubNumber);
    }

    private function generateUniqueLicenceNumber(?Member $lastMember): string
    {
        $lastLicenceNumber = $lastMember ? (int)substr($lastMember->getLicenceNumber(), 3) : 0;
        $newLicenceNumber = $lastLicenceNumber + 1;
        return sprintf('SKK%04d', $newLicenceNumber);
    }

    private function getMembersFromCSV(string $filePath): array
    {
        $handle = fopen($filePath, "r");
        fgetcsv($handle); // Ignorer l'en-tête

        $members = [];
        while (($data = fgetcsv($handle, 1000)) !== FALSE) {
            $members[] = $data;
        }

        fclose($handle);
        return $members;
    }

    private function getMembersFromXLSX(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // Ignorer la première ligne (en-tête)
        array_shift($rows);

        return $rows;
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
            $this->addFlash('error', 'Aucune commande trouvée pour annulation.');
            return $this->redirectToRoute('app_home');
        }

        // Récupération de la commande depuis la base de données
        $order = $entityManager->getRepository(Order::class)->find($orderId);

        // Vérification si la commande existe
        if (!$order) {
            // Logique en cas d'absence de la commande dans la base de données
            $this->addFlash('error', 'Commande non trouvée dans la base de données.');
            return $this->redirectToRoute('app_home');
        }

        // Mettre à jour le statut de la commande
        $order->setStatus("annulee");
        $entityManager->flush();

        // Nettoyage de la session
        $session->remove('cart');
        $session->remove('orderId');
        $session->remove('csvFile');

        // Afficher la page d'annulation
        return $this->render('payment/cancel.html.twig');
    }

    private function generateLicensePdf($data, $type): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true); // Pour les images externes ou locales

        $dompdf = new Dompdf($options);

        // Rendre le template en HTML selon le type de licence (club, adhérent individuel, ou culturel)
        $html = $this->renderView("licenses/" . $type . ".html.twig", [
            'data' => $data
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A6', 'landscape'); // Modifie la taille du papier pour correspondre à ton format d'impression
        $dompdf->render();

        // Sauvegarder le PDF dans un chemin spécifique
        $filePath = sprintf('%s/public/uploads/licence-' . $type . '%s.pdf', $this->getParameter('kernel.project_dir'), uniqid());
        file_put_contents($filePath, $dompdf->output());

        return $filePath;
    }

    #[Route("/view-pdf", name: "view_pdf")]
    public function viewPdfAsHtml(): Response
    {
        $data = [
            'logo' => 'uploads/66dc6a958e62e.jpg',
            'club_name' => 'Club Example',
            'numero' => '12345',
            'address' => '123 rue de la Paix',
            'address2' => null,
            'zip' => '75001',
            'city' => 'Paris',
        ];

        // On rend le template HTML directement
        return $this->render('licenses/club.html.twig', [
            'data' => $data
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function sendLicenseEmail($recipientEmail, $pdfPath, $context, $type, MailerInterface $mailer): void
    {
        $email = (new TemplatedEmail())
            ->from('no-reply@shinkyokai.com')
            ->to($recipientEmail)
            ->subject('Votre licence Shinkyokai')
            ->htmlTemplate('emails/' . $type . '.html.twig')
            ->context($context)
            ->attachFromPath($pdfPath, 'licence.pdf');

        $mailer->send($email);
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function sendCulturalArtsEmail($recipientEmail, $context, $type, MailerInterface $mailer): void
    {
        $email = (new TemplatedEmail())
            ->from('no-reply@shinkyokai.com')
            ->to($recipientEmail)
            ->subject('Vous vous êtes inscrit à un atelier sur le site de Shinkyokai')
            ->htmlTemplate('emails/' . $type . '.html.twig')
            ->context($context);

        $mailer->send($email);
    }
}
