<?php

namespace App\Tests\Command;

use App\Command\SendPendingCardsCommand;
use App\Entity\Member;
use App\Service\CardSender;
use App\Service\LicenseService;
use App\Service\MemberRegistrar;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\RecordingMailer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La commande lancée par le cron : lots bornés, mode test, simulation, garde-fou contre un envoi massif accidentel.
 */
class SendPendingCardsCommandTest extends DatabaseTestCase
{
    private RecordingMailer $mailer;
    private string $outputDir;
    private CardSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new RecordingMailer();
        $this->outputDir = sys_get_temp_dir() . "/skk-cron-" . uniqid();
        $licenses = new LicenseService(static::getContainer()->get("twig"), $this->mailer, new NullLogger(),
            static::getContainer()->getParameter("kernel.project_dir"), $this->outputDir, false, 0);
        $this->sender = new CardSender($this->entityManager, $licenses, new MemberRegistrar($this->entityManager), 5, 10, $this->outputDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->outputDir);
        parent::tearDown();
    }

    private function pendingMembers(int $count): void
    {
        $club = $this->makeClub("SHIN0011", "Shinkyobudo");
        $order = $this->makeOrder("complement-affiliation", "payee");
        for ($i = 1; $i <= $count; $i++) {
            $this->makeMember("Prenom$i", "Nom$i", sprintf("SKK%04d", 250 + $i), $club, $order);
        }
        $this->entityManager->flush();
    }

    private function cron(array $options = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $application->add(new SendPendingCardsCommand($this->sender));
        $tester = new CommandTester($application->find("app:licences:envoyer"));
        $tester->execute($options, ["interactive" => false]);

        return $tester;
    }

    public function testNothingToDoIsQuietAndHarmless(): void
    {
        $tester = $this->cron();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("Aucune carte à envoyer", $tester->getDisplay());
        self::assertCount(0, $this->mailer->sent);
    }

    public function testStatusShowsTheQueueAndSendsNothing(): void
    {
        $this->pendingMembers(3);

        $tester = $this->cron(["--status" => true]);

        self::assertStringContainsString("En attente", $tester->getDisplay());
        self::assertMatchesRegularExpression("/En attente \(jamais tentées\)\s+3/", $tester->getDisplay());
        self::assertCount(0, $this->mailer->sent);
    }

    public function testDryRunListsTheCardsWithoutSendingNorMarkingAnything(): void
    {
        $this->pendingMembers(3);

        $tester = $this->cron(["--dry-run" => true]);

        self::assertStringContainsString("SIMULATION", $tester->getDisplay());
        self::assertStringContainsString("SKK0251", $tester->getDisplay());
        self::assertCount(0, $this->mailer->sent);
        self::assertSame(3, $this->sender->status()["pending"]);
    }

    public function testTestRecipientSendsAtMostFiveCardsToThatAddressOnly(): void
    {
        $this->pendingMembers(12);

        $tester = $this->cron(["--test-recipient" => "moi@example.test", "--limit" => 40]);

        self::assertStringContainsString("MODE TEST", $tester->getDisplay());
        self::assertCount(5, $this->mailer->sent, "Le mode test est plafonné à 5 cartes");
        self::assertSame(["moi@example.test"], array_values(array_unique($this->mailer->recipients())));
        self::assertSame(12, $this->sender->status()["pending"], "Aucun adhérent n'est marqué comme servi");
    }

    public function testEachRunSendsOnlyOneBoundedBatchAndTheNextRunContinues(): void
    {
        $this->pendingMembers(7);

        $first = $this->cron(["--limit" => 3]);
        self::assertCount(3, $this->mailer->sent);
        self::assertStringContainsString("3 envoyée(s)", $first->getDisplay());

        $this->cron(["--limit" => 3]);
        $this->cron(["--limit" => 3]);
        self::assertCount(7, $this->mailer->sent);
        self::assertCount(7, array_unique($this->mailer->recipients()), "Chaque adhérent reçoit sa carte une seule fois");

        $this->cron(["--limit" => 3]);
        self::assertCount(7, $this->mailer->sent, "Une fois la file vide, plus aucun envoi");
    }

    public function testFailuresDoNotMakeTheCronCommandFailButAreRecorded(): void
    {
        $this->pendingMembers(3);
        $this->mailer->failFor("prenom2@example.test");

        $tester = $this->cron();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), "Un code d'erreur déclencherait un e-mail du cron à chaque minute");
        self::assertStringContainsString("ÉCHEC", $tester->getDisplay());
        self::assertSame(["pending" => 0, "retrying" => 1, "abandoned" => 0], $this->sender->status());
    }

    public function testRefusesToSendAnAbnormallyLargeBacklogUnlessForced(): void
    {
        // Garde-fou : si le rattrapage de l'historique a été oublié, des centaines d'anciens adhérents recevraient leur carte.
        $this->pendingMembers(200);

        $tester = $this->cron();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("anormalement élevé", $tester->getDisplay());
        self::assertCount(0, $this->mailer->sent);

        $forced = $this->cron(["--force-large-backlog" => true, "--limit" => 2]);
        self::assertSame(Command::SUCCESS, $forced->getStatusCode());
        self::assertCount(2, $this->mailer->sent);
    }

    public function testASecondSimultaneousRunDoesNothing(): void
    {
        $this->pendingMembers(3);
        $lock = $this->sender->acquireLock();

        $tester = $this->cron();

        self::assertStringContainsString("déjà en cours", $tester->getDisplay());
        self::assertCount(0, $this->mailer->sent);
        $this->sender->releaseLock($lock);

        $this->cron();
        self::assertCount(3, $this->mailer->sent);
    }

    public function testSentCardsAreRecordedInTheDatabase(): void
    {
        $this->pendingMembers(2);

        $this->cron();

        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Member::class)->findAll() as $member) {
            self::assertNotNull($member->getCardSentAt());
            self::assertSame(0, $member->getCardAttempts());
        }
    }
}
