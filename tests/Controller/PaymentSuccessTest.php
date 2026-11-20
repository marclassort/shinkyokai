<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use App\Tests\Support\PaymentFlowTestCase;
use App\Tests\Support\MemberFile;
use App\Tests\Support\PdfInspector;

/**
 * Parcours complet du retour de paiement : enregistrement en base, numéros de licence, génération et envoi des cartes.
 */
class PaymentSuccessTest extends PaymentFlowTestCase
{
    /**
     * Le 6 novembre, un lot de 107 adhérents n'a pas survécu à la requête HTTP : ici on vérifie que TOUT est enregistré
     * et que toutes les cartes partent.
     */
    public function testNewClubWith107MembersIsFullyRegisteredAndEveryCardIsSent(): void
    {
        $order = $this->startCheckout("inscription-club", $this->clubCart(107), 585.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->rowCount(Club::class));
        self::assertSame(107, $this->rowCount(Member::class));

        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $order->getId());
        self::assertSame("payee", $order->getStatus());
        self::assertSame(585.0, $order->getTotalAmount());
        self::assertSame("SHIN0001", $order->getClub()->getClubNumber());

        $licences = array_map(
            static fn(Member $m) => $m->getLicenceNumber(),
            $this->entityManager->getRepository(Member::class)->findBy([], ["id" => "ASC"])
        );
        self::assertSame(array_map(static fn($i) => sprintf("SKK%04d", $i), range(1, 107)), $licences, "Numéros consécutifs, dans l'ordre du fichier");

        self::assertCount(108, $this->mailer->sent, "La carte du club + une carte par adhérent");
        self::assertCount(1, array_filter($this->mailer->sent, fn($m) => $m->getTo()[0]->getAddress() === "club@example.test"));
        foreach ($this->mailer->sent as $email) {
            self::assertCount(1, $email->getAttachments());
        }
    }

    public function testPaymentNotConfirmedByStripeRegistersAndSendsNothing(): void
    {
        $order = $this->startCheckout("inscription-club", $this->clubCart(5), 75.0);
        $this->payment->paid = false;

        $this->client->request("GET", "/paiement-succes");

        self::assertFalse($this->client->getResponse()->isSuccessful(), "Un paiement non confirmé ne doit jamais aboutir à une page de succès");
        self::assertSame(1, $this->payment->calls, "La vérification Stripe a bien eu lieu");
        self::assertSame(0, $this->rowCount(Club::class));
        self::assertSame(0, $this->rowCount(Member::class));
        self::assertCount(0, $this->mailer->sent);
        $this->entityManager->clear();
        self::assertSame("en-cours", $this->entityManager->find(Order::class, $order->getId())->getStatus());
    }

    public function testAnInvalidMemberFileCannotLeaveAHalfRegisteredClub(): void
    {
        // Fichier corrompu après paiement : rien ne doit être enregistré à moitié (une seule transaction).
        $cart = $this->clubCart(5);
        file_put_contents($cart["club"]["csvFilePath"], "n'importe quoi");
        $order = $this->startCheckout("inscription-club", $cart, 75.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseStatusCodeSame(500);
        self::assertSame(0, $this->rowCount(Club::class), "Pas de club sans adhérents");
        self::assertSame(0, $this->rowCount(Member::class));
        $this->entityManager->clear();
        self::assertSame("en-cours", $this->entityManager->find(Order::class, $order->getId())->getStatus());
    }

    public function testAMailFailureNeverCancelsTheRegistration(): void
    {
        $this->startCheckout("inscription-club", $this->clubCart(10), 100.0);
        $this->mailer->failFor("famille003@example.test"); // adhérents 5 et 6

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(10, $this->rowCount(Member::class), "Les adhérents sont en base même si des cartes n'ont pas pu partir");
        self::assertCount(9, $this->mailer->sent, "Club + 10 adhérents - 2 envois en échec");

        // Les cartes en échec restent « en attente » : le cron (ou la prochaine exécution) les retentera.
        $this->entityManager->clear();
        $members = $this->entityManager->getRepository(Member::class)->findAll();
        $pending = array_filter($members, static fn(Member $m) => $m->getCardSentAt() === null);
        self::assertCount(2, $pending);
        foreach ($pending as $member) {
            self::assertSame(1, $member->getCardAttempts());
        }
        self::assertCount(8, array_filter($members, static fn(Member $m) => $m->getCardSentAt() !== null));
    }

    public function testReloadingTheSuccessPageNeverDuplicatesAnything(): void
    {
        $this->startCheckout("inscription-club", $this->clubCart(6), 80.0);
        $this->client->request("GET", "/paiement-succes");
        self::assertResponseIsSuccessful();
        $mails = count($this->mailer->sent);

        $this->client->request("GET", "/paiement-succes"); // l'utilisateur recharge la page

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), "Le rechargement ne doit pas provoquer d'erreur serveur");
        self::assertSame(1, $this->rowCount(Club::class));
        self::assertSame(6, $this->rowCount(Member::class));
        self::assertCount($mails, $this->mailer->sent, "Aucune carte en double");
    }

    public function testComplementContinuesTheNumberingOfTheExistingClub(): void
    {
        $club = new Club();
        $club->setName("Shinkyobudo");
        $club->setLogo("logo.png");
        $club->setEmail("presidence@example.test");
        $club->setPresidentName("P");
        $club->setTreasurerName("T");
        $club->setAddress("1 rue");
        $club->setPostalCode("64000");
        $club->setCity("Pau");
        $club->setCountry("France");
        $club->setClubNumber("SHIN0011");
        $club->setSportSeason("2026-2027");
        $existing = new Member();
        $existing->setFirstName("Chloe");
        $existing->setLastName("SIMULE-C");
        $existing->setSex("Masculin");
        $existing->setBirthDate("01/01/1990");
        $existing->setLicenceNumber("SKK0141");
        $existing->setClub($club);
        $this->entityManager->persist($club);
        $this->entityManager->persist($existing);

        $file = MemberFile::xlsx(MemberFile::rows(30));
        $this->tempFiles[] = $file;
        $order = $this->startCheckout("complement-affiliation", ["club_complement" => [
            "club_number" => "SHIN0011", "members" => 30, "csvFilePath" => $file, "price_per_member" => 5,
        ]], 150.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(31, $this->rowCount(Member::class));
        self::assertSame(1, $this->rowCount(Club::class), "Le complément n'ajoute pas de club");
        $this->entityManager->clear();
        $licences = array_map(static fn(Member $m) => $m->getLicenceNumber(), $this->entityManager->getRepository(Member::class)->findBy(["commande" => $order], ["id" => "ASC"]));
        self::assertSame("SKK0142", $licences[0]);
        self::assertSame("SKK0171", $licences[29]);
        self::assertCount(30, $this->mailer->sent, "Une carte par nouvel adhérent (pas de carte de club)");
        self::assertSame("payee", $this->entityManager->find(Order::class, $order->getId())->getStatus());
    }

    public function testIndividualRegistrationGetsTheNextLicenceNumber(): void
    {
        $existing = new Member();
        $existing->setFirstName("A");
        $existing->setLastName("B");
        $existing->setSex("Masculin");
        $existing->setBirthDate("01/01/1990");
        $existing->setLicenceNumber("SKK0194");
        $this->entityManager->persist($existing);

        $order = $this->startCheckout("inscription-individuelle", ["club_individuel" => [
            "price" => 10, "first_name" => "Jean", "last_name" => "Dupont", "birth_date" => "12/03/1985",
            "sex" => "Masculin", "email" => "jean@example.test",
        ]], 10.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $member = $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "Dupont"]);
        self::assertSame("SKK0195", $member->getLicenceNumber());
        self::assertSame($order->getId(), $member->getCommande()->getId());
        self::assertSame(["jean@example.test"], $this->mailer->recipients());
    }

    public function testCulturalArtsRegistrationIsRecordedAndConfirmed(): void
    {
        $this->startCheckout("inscription-arts-culturels", ["cultural_registration" => [
            "activity" => "Sumi-e", "price" => 20, "first_name" => "Claire", "last_name" => "Exemple",
            "birth_date" => "01/02/1990", "sex" => "Féminin", "email" => "claire@example.test",
        ]], 20.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->rowCount(Member::class));
        self::assertSame(["claire@example.test"], $this->mailer->recipients());
    }

    public function testFirstNamesStartWithACapitalInTheDatabaseAndOnTheCard(): void
    {
        $rows = MemberFile::rows(3);
        $rows[0]["first_name"] = "karim";
        $rows[1]["first_name"] = "anne-sophie";
        $rows[2]["first_name"] = "SACHA";
        $file = MemberFile::xlsx($rows);
        $this->tempFiles[] = $file;
        $cart = $this->clubCart(3);
        $cart["club"]["csvFilePath"] = $file;
        $this->startCheckout("inscription-club", $cart, 65.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        $firstNames = array_map(static fn(Member $m) => $m->getFirstName(), $this->entityManager->getRepository(Member::class)->findBy([], ["id" => "ASC"]));
        self::assertSame(["Karim", "Anne-Sophie", "Sacha"], $firstNames, "En base de données");

        if (PdfInspector::available()) {
            $texts = array_map(static fn($mail) => PdfInspector::inspect($mail->getAttachments()[0]->getBody())["text"], array_slice($this->mailer->sent, 1));
            self::assertMatchesRegularExpression("/Prénom\s*:\s*Karim/u", $texts[0], "Sur la carte (PDF)");
            self::assertMatchesRegularExpression("/Prénom\s*:\s*Anne-Sophie/u", $texts[1]);
            self::assertMatchesRegularExpression("/Prénom\s*:\s*Sacha\b/u", $texts[2], "Tout en capitales → Sacha");
        }
    }

    public function testIndividualFirstNameFromTheFormIsCapitalisedOnTheCard(): void
    {
        $this->startCheckout("inscription-individuelle", ["club_individuel" => [
            "price" => 10, "first_name" => "SARAH", "last_name" => "TESTEUR", "birth_date" => "12/03/2012",
            "sex" => "Féminin", "email" => "sarah@example.test",
        ]], 10.0);

        $this->client->request("GET", "/paiement-succes");

        $this->entityManager->clear();
        self::assertSame("Sarah", $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "TESTEUR"])->getFirstName());
        if (PdfInspector::available()) {
            self::assertMatchesRegularExpression("/Sarah/u", PdfInspector::inspect($this->mailer->sent[0]->getAttachments()[0]->getBody())["text"]);
        }
    }

    public function testInlineSendingNeverRunsAtTheSameTimeAsAnotherSender(): void
    {
        // La tâche planifiée (ou une autre inscription) est en train d'envoyer : l'inscription enregistre tout mais n'envoie rien,
        // pour qu'une carte ne parte jamais deux fois ; les cartes restent en attente et seront envoyées par l'autre exécution.
        $this->startCheckout("inscription-club", $this->clubCart(4), 70.0);
        $sender = static::getContainer()->get(\App\Service\CardSender::class);
        $lock = $sender->acquireLock();
        self::assertNotNull($lock);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->rowCount(Member::class), "Tout est enregistré");
        self::assertCount(0, $this->mailer->sent, "Rien n'est envoyé pendant qu'un autre envoi est en cours");
        self::assertSame(5, $sender->status()["pending"], "Les cartes (club + 4 adhérents) restent en attente");

        $sender->releaseLock($lock);
        $sender->deliver($sender->pending(100));
        self::assertCount(5, $this->mailer->sent, "Elles partent ensuite, une seule fois chacune");
    }
}
