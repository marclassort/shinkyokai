<?php

namespace App\Tests\Command;

use App\Command\RegulariseComplementCommand;
use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use App\Service\CardSender;
use App\Service\LicenceNumberGenerator;
use App\Service\LicenseService;
use App\Service\MemberFileReader;
use App\Service\MemberRegistrar;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MemberFile;
use App\Tests\Support\RecordingMailer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Régularisation de la commande #52 du 6 novembre 2026 : 107 adhérents, 56 cartes déjà envoyées (SKK0195 à SKK0250),
 * rien enregistré en base, et deux adhérents arrivés depuis (les « SIMULE-A ») qui ont reçu SKK0195 et SKK0196.
 */
class RegulariseComplementCommandTest extends DatabaseTestCase
{
    private RecordingMailer $mailer;
    private string $outputDir;
    private string $file;
    private Club $club;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new RecordingMailer();
        $this->outputDir = sys_get_temp_dir() . "/skk-regul-" . uniqid();
        $this->file = MemberFile::xlsx(MemberFile::rows(107));

        // --- État de la base le 20 novembre ---
        $this->club = $this->makeClub("SHIN0011", "Shinkyobudo");
        $pau = $this->makeClub("SHIN0021", "Pau Académie Martiale");
        $this->makeMember("Chloe", "SIMULE-C", "SKK0141", $this->club, null, "2026-2027", "05/05/1990");
        $this->makeMember("Ancien", "Membre", "SKK0194", $pau, null, "2026-2027", "02/02/1980");
        // Commande n° 53 du 18/11 (10 €, payée) : les deux adhérents arrivés après l'incident
        $order53 = $this->makeOrder("complement-affiliation", "payee", 10.0, new \DateTimeImmutable("2026-11-18 07:26:00"));
        $remplace1 = $this->makeMember("Alice", "SIMULE-A", "SKK0195", $pau, $order53, "2026-2027", "03/03/1975");
        $remplace2 = $this->makeMember("Bruno", "SIMULE-B", "SKK0196", $pau, $order53, "2026-2027", "04/04/2012");
        $remplace1->setEmail("famille-simulee@example.test");
        $remplace2->setEmail("famille-simulee@example.test");

        // La commande interrompue : toujours « en-cours », non rattachée à un club
        $this->order = $this->makeOrder("complement-affiliation", "en-cours", 535.0, new \DateTimeImmutable("2026-11-06 21:57:00"));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        (new Filesystem())->remove($this->outputDir);
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $licenseService = new LicenseService(
            static::getContainer()->get("twig"),
            $this->mailer,
            new NullLogger(),
            static::getContainer()->getParameter("kernel.project_dir"),
            $this->outputDir,
            false,
            0,
        );
        $command = new RegulariseComplementCommand(
            $this->entityManager,
            new MemberFileReader(),
            new MemberRegistrar($this->entityManager),
            new LicenceNumberGenerator($this->entityManager),
            new CardSender($this->entityManager, $licenseService, new MemberRegistrar($this->entityManager), 5, 10, $this->outputDir),
        );
        $application = new Application(self::$kernel);
        $application->add($command);

        return new CommandTester($application->find("app:licences:regulariser"));
    }

    private function regularise(bool $execute = true, array $extra = []): CommandTester
    {
        $tester = $this->tester();
        $input = [
            "order" => $this->order->getId(),
            "file" => $this->file,
            "--club-number" => "SHIN0011",
            "--first-licence" => 195,
            "--already-sent" => 56,
        ];
        $execute && $input["--execute"] = true;
        $tester->execute($input + $extra, ["interactive" => false]);

        return $tester;
    }

    private function runWith(array $input): CommandTester
    {
        $tester = $this->tester();
        $tester->execute($input + ["order" => $this->order->getId(), "file" => $this->file], ["interactive" => false]);

        return $tester;
    }

    private function licenceOf(string $lastName, string $firstName): ?string
    {
        $this->entityManager->clear();
        $member = $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => $lastName, "firstName" => $firstName]);

        return $member?->getLicenceNumber();
    }

    public function testDryRunShowsThePlanAndChangesNothing(): void
    {
        $membersBefore = $this->entityManager->getRepository(Member::class)->count([]);

        $tester = $this->regularise(execute: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString("SIMULATION", $display);
        self::assertStringContainsString("SKK0195 → SKK0301", $display);
        self::assertStringContainsString("SKK0302", $display, "Nouveau numéro du 1er adhérent renuméroté");
        self::assertStringContainsString("SKK0303", $display, "Nouveau numéro du 2e adhérent renuméroté");
        self::assertStringContainsString("lignes 57 à 107", $display);

        $this->entityManager->clear();
        self::assertSame($membersBefore, $this->entityManager->getRepository(Member::class)->count([]), "Aucun adhérent créé");
        self::assertSame("SKK0195", $this->licenceOf("SIMULE-A", "Alice"), "Aucune renumérotation");
        self::assertSame("en-cours", $this->entityManager->find(Order::class, $this->order->getId())->getStatus());
        self::assertCount(0, $this->mailer->sent, "Aucun e-mail en simulation");
    }

    public function testRegularisesTheWholeLotWithTheRightLicenceNumbers(): void
    {
        $tester = $this->regularise();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        // Les 56 premiers gardent le numéro déjà imprimé sur leur carte, les 51 suivants continuent la suite.
        self::assertSame("SKK0195", $this->licenceOf("NOM001", "Prenom001"));
        self::assertSame("SKK0250", $this->licenceOf("NOM056", "Prenom056"));
        self::assertSame("SKK0251", $this->licenceOf("NOM057", "Prenom057"));
        self::assertSame("SKK0301", $this->licenceOf("NOM107", "Prenom107"));
        // Les deux adhérents arrivés depuis reçoivent les numéros les plus élevés.
        self::assertSame("SKK0302", $this->licenceOf("SIMULE-A", "Alice"));
        self::assertSame("SKK0303", $this->licenceOf("SIMULE-B", "Bruno"));
        // Les autres adhérents ne bougent pas.
        self::assertSame("SKK0141", $this->licenceOf("SIMULE-C", "Chloe"));
        self::assertSame("SKK0194", $this->licenceOf("Membre", "Ancien"));

        $numbers = $this->allLicenceNumbers();
        self::assertCount(4 + 107, $numbers);
        self::assertCount(count($numbers), array_unique($numbers), "Aucun doublon de numéro de licence");

        // Le prochain adhérent qui s'inscrira recevra SKK0304.
        self::assertSame(304, (new LicenceNumberGenerator($this->entityManager))->nextNumber());
    }

    public function testOrderIsMarkedPaidAndAttachedToTheClub(): void
    {
        $this->regularise();

        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $this->order->getId());
        self::assertSame("payee", $order->getStatus());
        self::assertSame("SHIN0011", $order->getClub()?->getClubNumber());
        self::assertSame(535.0, $order->getTotalAmount(), "Le montant encaissé n'est pas modifié");

        $members = $this->entityManager->getRepository(Member::class)->findBy(["commande" => $order]);
        self::assertCount(107, $members);
        foreach ($members as $member) {
            self::assertSame("SHIN0011", $member->getClub()->getClubNumber());
            self::assertSame("2026-2027", $member->getSportSeason());
        }
    }

    public function testOnlyThe51RemainingMembersAndTheTwoReplacedCardsAreMailed(): void
    {
        $this->regularise();

        self::assertCount(53, $this->mailer->sent, "51 nouvelles cartes + 2 cartes de remplacement");

        $recipients = $this->mailer->recipients();
        // Famille n (n = 1..28) = lignes 1 à 56 : déjà servies, ne doivent RIEN recevoir.
        for ($family = 1; $family <= 28; $family++) {
            self::assertNotContains(sprintf("famille%03d@example.test", $family), $recipients, "famille$family a déjà reçu sa carte");
        }
        // Familles 29..54 = lignes 57 à 107 : à servir.
        for ($family = 29; $family <= 54; $family++) {
            self::assertContains(sprintf("famille%03d@example.test", $family), $recipients, "famille$family n'a pas reçu sa carte");
        }

        $replacements = array_filter($this->mailer->sent, static fn($m) => str_contains($m->getSubject(), "remplace"));
        self::assertCount(2, $replacements);
        foreach ($replacements as $email) {
            self::assertSame("famille-simulee@example.test", $email->getTo()[0]->getAddress());
        }
        foreach ($this->mailer->sent as $email) {
            self::assertCount(1, $email->getAttachments());
        }
    }

    public function testRunningItAgainDoesNothingAndSendsNothing(): void
    {
        $this->regularise();
        $mailsAfterFirstRun = count($this->mailer->sent);
        $membersAfterFirstRun = $this->entityManager->getRepository(Member::class)->count([]);

        $second = $this->regularise();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString("déjà « payee »", $second->getDisplay());
        self::assertCount($mailsAfterFirstRun, $this->mailer->sent, "Aucun e-mail en double");
        self::assertSame($membersAfterFirstRun, $this->entityManager->getRepository(Member::class)->count([]), "Aucun adhérent en double");
    }

    public function testMailRowsResendsOnlyTheRequestedCardsWithoutCreatingAnyone(): void
    {
        $this->regularise();
        $before = count($this->mailer->sent);
        $membersBefore = $this->entityManager->getRepository(Member::class)->count([]);

        $tester = $this->runWith([
            "--club-number" => "SHIN0011", "--first-licence" => 195, "--already-sent" => 56,
            "--mail-rows" => "60-61", "--execute" => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount($before + 2, $this->mailer->sent);
        self::assertSame($membersBefore, $this->entityManager->getRepository(Member::class)->count([]));
        self::assertSame("SKK0254", $this->licenceOf("NOM060", "Prenom060"), "Renvoi avec le numéro déjà attribué");
    }

    public function testSmtpFailureNeverRollsBackTheDatabaseAndIsReportedByName(): void
    {
        $this->mailer->failFor("famille040@example.test");

        $tester = $this->regularise();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), "Le code retour signale l'échec partiel");
        self::assertStringContainsString("n'ont pas pu être envoyées", $tester->getDisplay());
        self::assertStringContainsString("--mail-rows", $tester->getDisplay());
        self::assertSame("SKK0301", $this->licenceOf("NOM107", "Prenom107"), "L'enregistrement en base est conservé");
        self::assertGreaterThan(40, count($this->mailer->sent), "Les cartes suivantes sont quand même parties");
    }

    public function testNoCollisionWhenNobodyHoldsAReservedNumber(): void
    {
        // Base sans les SIMULE-A : rien à renuméroter.
        $this->entityManager->remove($this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-A"]));
        $this->entityManager->remove($this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-B"]));
        $this->entityManager->flush();

        $tester = $this->regularise();

        self::assertStringContainsString("Aucun adhérent existant à renuméroter", $tester->getDisplay());
        self::assertCount(51, $this->mailer->sent);
    }

    public function testRenumberedMembersGoAfterTheHighestExistingNumber(): void
    {
        // Un adhérent plus récent occupe déjà SKK0400 : les remplaçants passent après lui.
        $this->makeMember("Tard", "Venu", "SKK0400", $this->club, null, "2026-2027", "09/09/1999");
        $this->entityManager->flush();

        $this->regularise();

        self::assertSame("SKK0401", $this->licenceOf("SIMULE-A", "Alice"));
        self::assertSame("SKK0402", $this->licenceOf("SIMULE-B", "Bruno"));
        self::assertSame("SKK0400", $this->licenceOf("Venu", "Tard"));
    }

    /**
     * @dataProvider invalidInvocations
     */
    public function testRefusesInvalidInvocationsWithoutTouchingAnything(array $input, string $message): void
    {
        $membersBefore = $this->entityManager->getRepository(Member::class)->count([]);

        $tester = $this->runWith($input + ["--execute" => true]);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame($membersBefore, $this->entityManager->getRepository(Member::class)->count([]));
        self::assertCount(0, $this->mailer->sent);
    }

    public static function invalidInvocations(): iterable
    {
        $ok = ["--club-number" => "SHIN0011", "--first-licence" => 195, "--already-sent" => 56];

        yield "club inconnu" => [["--club-number" => "SHIN9999"] + $ok, "Club introuvable"];
        yield "numéro de départ manquant" => [["--club-number" => "SHIN0011"], "--first-licence"];
        yield "trop de cartes déjà envoyées" => [["--already-sent" => 500] + $ok, "--already-sent"];
        yield "plage invalide" => [["--mail-rows" => "90-200"] + $ok, "--mail-rows"];
        yield "fichier introuvable" => [["file" => "/nulle/part.xlsx"] + $ok, "Fichier introuvable"];
    }

    public function testRefusesAnOrderAlreadyAttachedToAnotherClub(): void
    {
        $this->order->setClub($this->entityManager->getRepository(Club::class)->findOneBy(["clubNumber" => "SHIN0021"]));
        $this->entityManager->flush();

        $tester = $this->regularise();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("autre club", $tester->getDisplay());
        self::assertSame("SKK0195", $this->licenceOf("SIMULE-A", "Alice"));
    }

    public function testAlreadySentCardsAreRecordedAsSentAndTheOthersAreMarkedOnceDelivered(): void
    {
        $this->regularise(execute: true, extra: ["--already-sent-at" => "2026-11-06 22:03"]);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Member::class);
        $first = $repo->findOneBy(["lastName" => "NOM001"]);
        self::assertSame("2026-11-06 22:03", $first->getCardSentAt()->format("Y-m-d H:i"), "Carte déjà envoyée : date enregistrée");
        self::assertSame("2026-11-06 22:03", $repo->findOneBy(["lastName" => "NOM056"])->getCardSentAt()->format("Y-m-d H:i"));
        self::assertGreaterThan(new \DateTimeImmutable("2026-11-20"), $repo->findOneBy(["lastName" => "NOM057"])->getCardSentAt(), "Carte envoyée par la commande : marquée à l'instant");
        self::assertNotNull($repo->findOneBy(["lastName" => "NOM107"])->getCardSentAt());
        self::assertNotNull($repo->findOneBy(["lastName" => "SIMULE-A"])->getCardSentAt(), "Carte de remplacement envoyée");

        // Plus rien en attente : le cron n'enverra rien de plus.
        $sender = new CardSender($this->entityManager, $this->licenseServiceForTest(), new MemberRegistrar($this->entityManager), 5, 10, $this->outputDir);
        self::assertSame(["pending" => 0, "retrying" => 0, "abandoned" => 0], $sender->status());
    }

    public function testCardsInFailureStayPendingSoTheCronCanRetryThem(): void
    {
        $this->mailer->failFor("famille040@example.test");

        $this->regularise();

        $this->entityManager->clear();
        $failed = $this->entityManager->getRepository(Member::class)->findBy(["email" => "famille040@example.test"]);
        self::assertCount(2, $failed);
        foreach ($failed as $member) {
            self::assertNull($member->getCardSentAt(), "Une carte non envoyée n'est pas marquée comme envoyée");
            self::assertSame(1, $member->getCardAttempts());
            self::assertStringContainsString("Panne SMTP", $member->getCardLastError());
        }
    }

    public function testItWaitsForTheCronToFinishAndNeverRunsAtTheSameTime(): void
    {
        $holder = new CardSender($this->entityManager, $this->licenseServiceForTest(), new MemberRegistrar($this->entityManager), 5, 10, $this->outputDir);
        $lock = $holder->acquireLock();
        self::assertNotNull($lock);
        self::assertNull($holder->acquireLock(), "Une 2e exécution simultanée est refusée");
        $holder->releaseLock($lock);
        self::assertNotNull($lock = $holder->acquireLock(), "Le verrou est de nouveau disponible une fois libéré");
        $holder->releaseLock($lock);
    }

    private function licenseServiceForTest(): LicenseService
    {
        return new LicenseService(static::getContainer()->get("twig"), $this->mailer, new NullLogger(), static::getContainer()->getParameter("kernel.project_dir"), $this->outputDir, false, 0);
    }

    public function testNoMailRegistersEverythingAndLeavesTheCardsPendingForTheQueue(): void
    {
        $tester = $this->regularise(execute: true, extra: ["--no-mail" => true, "--already-sent-at" => "2026-11-06 22:03"]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString("restent « en attente »", $tester->getDisplay());
        self::assertCount(0, $this->mailer->sent, "Rien n'est envoyé");
        self::assertSame("SKK0301", $this->licenceOf("NOM107", "Prenom107"), "Mais tout est enregistré");

        // 51 nouveaux adhérents + 2 cartes de remplacement attendent dans la file ; les 56 premiers ne reçoivent rien.
        $sender = new CardSender($this->entityManager, $this->licenseServiceForTest(), new MemberRegistrar($this->entityManager), 5, 10, $this->outputDir);
        self::assertSame(["pending" => 53, "retrying" => 0, "abandoned" => 0], $sender->status());

        $items = $sender->pending(100);
        $replacements = array_filter($items, static fn($i) => $i["card"]["replaces"] !== null);
        self::assertCount(2, $replacements);
        self::assertEqualsCanonicalizing(["SKK0195", "SKK0196"], array_map(static fn($i) => $i["card"]["replaces"], array_values($replacements)));

        // Le cron envoie ensuite exactement ces 53 cartes, une seule fois chacune.
        $sender->deliver($items);
        self::assertCount(53, $this->mailer->sent);
        self::assertCount(2, array_filter($this->mailer->sent, static fn($m) => str_contains($m->getSubject(), "remplace")));
        self::assertSame(["pending" => 0, "retrying" => 0, "abandoned" => 0], $sender->status());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-A"])->getCardReplaces(), "Le marqueur de remplacement est effacé une fois la carte envoyée");
    }

    public function testPlanWarnsWhenARenumberedMemberCannotBeReachedByTheQueue(): void
    {
        $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-A"])->setCommande(null);
        $this->entityManager->flush();

        $display = $this->regularise(execute: false)->getDisplay();

        self::assertStringContainsString("NON (pas d'e-mail ou commande non payée)", $display);
    }

    /**
     * Ligne dont le prénom et le nom sont inversés dans le fichier : « Camille DURAND » saisi à l'envers (prénom DURAND, nom Camille). Sa carte est déjà partie
     * et ne sera PAS renvoyée (décision du club) : seule la base de données doit être juste.
     */
    public function testSwappedNamesAreCorrectedInTheDatabaseWithoutResendingTheCard(): void
    {
        $this->useFileWithSwappedRow31();

        $tester = $this->regularise(execute: true, extra: ["--swap-names" => "31", "--already-sent-at" => "2026-11-06 22:03"]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString("remis dans l'ordre", $tester->getDisplay());

        $this->entityManager->clear();
        $member = $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "DURAND"]);
        self::assertNotNull($member, "Le nom de famille est DURAND");
        self::assertSame("Camille", $member->getFirstName(), "Le prénom est Camille");
        self::assertNull($this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "Camille"]), "Plus aucun adhérent « Camille » en nom de famille");
        self::assertSame("SKK0225", $member->getLicenceNumber(), "Il garde le numéro de sa carte déjà envoyée (ligne 31 : 195 + 30)");
        self::assertSame("2026-11-06 22:03", $member->getCardSentAt()->format("Y-m-d H:i"), "Sa carte est considérée comme servie");

        self::assertCount(53, $this->mailer->sent, "51 nouvelles cartes + 2 SIMULE-A : aucune carte renvoyée pour la ligne 31");
        self::assertNotContains("famille016@example.test", $this->mailer->recipients(), "Le destinataire de la ligne 31 ne reçoit rien");
    }

    /** Capacité générale de la commande (non utilisée pour la ligne 31) : renvoyer une carte erronée sous le même numéro. */
    public function testResendRowsReplacesAnAlreadySentCardUnderTheSameNumber(): void
    {
        $this->useFileWithSwappedRow31();

        $this->regularise(execute: true, extra: ["--swap-names" => "31", "--resend-rows" => "31", "--already-sent-at" => "2026-11-06 22:03"]);

        self::assertCount(54, $this->mailer->sent);
        $corrected = array_values(array_filter($this->mailer->sent, static fn($m) => ($m->getContext()["replaces"] ?? null) === "SKK0225"));
        self::assertCount(1, $corrected);
        self::assertSame("famille016@example.test", $corrected[0]->getTo()[0]->getAddress());
    }

    private function useFileWithSwappedRow31(): void
    {
        $rows = MemberFile::rows(107);
        [$rows[30]["first_name"], $rows[30]["last_name"]] = ["DURAND", "Camille"];
        @unlink($this->file);
        $this->file = MemberFile::xlsx($rows);
    }

    public function testRowListsMustBeWithinTheFile(): void
    {
        $tester = $this->regularise(execute: true, extra: ["--swap-names" => "500"]);

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("--swap-names", $tester->getDisplay());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "NOM001"]), "Rien n'est enregistré");
    }

    public function testRegularisedMembersHaveCapitalisedFirstNames(): void
    {
        $rows = MemberFile::rows(107);
        $rows[52]["first_name"] = "karim";
        @unlink($this->file);
        $this->file = MemberFile::xlsx($rows);

        $this->regularise(execute: true);

        self::assertSame("SKK0247", $this->licenceOf("NOM053", "Karim"), "Enregistré en base avec la majuscule");
    }
}
