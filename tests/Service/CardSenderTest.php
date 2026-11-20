<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Member;
use App\Service\CardSender;
use App\Service\LicenseService;
use App\Service\MemberRegistrar;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\RecordingMailer;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * File d'envoi des cartes : qui est éligible, résultat mémorisé carte par carte, nouvel essai, abandon.
 */
class CardSenderTest extends DatabaseTestCase
{
    private RecordingMailer $mailer;
    private string $outputDir;
    private CardSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new RecordingMailer();
        $this->outputDir = sys_get_temp_dir() . "/skk-cards-" . uniqid();
        $this->sender = $this->senderWith(5, 10);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->outputDir);
        parent::tearDown();
    }

    private function senderWith(int $maxAttempts, int $retryMinutes): CardSender
    {
        $licenses = new LicenseService(static::getContainer()->get("twig"), $this->mailer, new NullLogger(),
            static::getContainer()->getParameter("kernel.project_dir"), $this->outputDir, false, 0);

        return new CardSender($this->entityManager, $licenses, new MemberRegistrar($this->entityManager), $maxAttempts, $retryMinutes, $this->outputDir);
    }

    /** Un club et $count adhérents d'une commande payée, prêts à recevoir leur carte. */
    private function paidClubWithMembers(int $count, string $orderType = "complement-affiliation"): array
    {
        $club = $this->makeClub("SHIN00" . random_int(10, 99));
        $order = $this->makeOrder($orderType, "payee");
        $order->setClub($club);
        $members = [];
        for ($i = 1; $i <= $count; $i++) {
            $members[] = $this->makeMember("Prenom$i", "Nom$i", sprintf("SKK%04d", random_int(1000, 9999) + $i * 10000), $club, $order);
        }
        $this->entityManager->flush();

        return [$club, $order, $members];
    }

    public function testOnlyMembersOfAPaidOrderWithALicenceAndAnEmailAreEligible(): void
    {
        [$club, , $members] = $this->paidClubWithMembers(1);

        $unpaid = $this->makeOrder("complement-affiliation", "en-cours");
        $this->makeMember("Impaye", "Test", "SKK0501", $club, $unpaid);
        $this->makeMember("SansCommande", "Test", "SKK0502", $club, null);   // saisie manuelle / historique
        $noEmail = $this->makeMember("SansEmail", "Test", "SKK0503", $club, $members[0]->getCommande());
        $noEmail->setEmail(null);
        $cultural = $this->makeMember("Culturel", "Test", "x", $club, $members[0]->getCommande());
        $cultural->setLicenceNumber(null);
        $already = $this->makeMember("Deja", "Envoye", "SKK0504", $club, $members[0]->getCommande());
        $already->markCardSent();
        $this->entityManager->flush();

        $items = $this->sender->pending(100);

        self::assertCount(1, $items, "Un seul adhérent doit recevoir sa carte");
        self::assertSame($members[0]->getId(), $items[0]["entity"]->getId());
        self::assertSame("club-membre", $items[0]["card"]["type"]);
    }

    public function testClubCardIsOnlyForAClubRegistrationNeverForAComplement(): void
    {
        [$clubA] = $this->paidClubWithMembers(1, "inscription-club");
        [$clubB] = $this->paidClubWithMembers(1, "complement-affiliation");

        $clubs = array_filter(array_column($this->sender->pending(100), "entity"), static fn($e) => $e instanceof Club);

        self::assertSame([$clubA->getId()], array_map(static fn(Club $c) => $c->getId(), array_values($clubs)));
        self::assertNotContains($clubB->getId(), array_map(static fn(Club $c) => $c->getId(), $clubs));
    }

    public function testClubsComeFirstThenMembersInRegistrationOrderAndTheLimitIsRespected(): void
    {
        [, , $members] = $this->paidClubWithMembers(5, "inscription-club");

        $items = $this->sender->pending(3);

        self::assertCount(3, $items);
        self::assertInstanceOf(Club::class, $items[0]["entity"]);
        self::assertSame($members[0]->getId(), $items[1]["entity"]->getId());
        self::assertSame($members[1]->getId(), $items[2]["entity"]->getId());
    }

    public function testIndividualMemberWithoutClubGetsAnIndividualCard(): void
    {
        $order = $this->makeOrder("inscription-individuelle", "payee");
        $this->makeMember("Jean", "Dupont", "SKK0195", null, $order);
        $this->entityManager->flush();

        $card = $this->sender->pending(10)[0]["card"];

        self::assertSame("membre-individuel", $card["type"]);
        self::assertSame("SKK0195", $card["data"]["licence"]);
        self::assertSame("Jean", $card["data"]["first_name"]);
    }

    public function testDeliveredCardsAreMarkedSentOneByOneAndNeverSentTwice(): void
    {
        $this->paidClubWithMembers(4);

        $result = $this->sender->deliver($this->sender->pending(100));
        $second = $this->sender->deliver($this->sender->pending(100));

        self::assertSame(["sent" => 4, "failed" => 0, "stopped" => false], $result);
        self::assertSame(0, $second["sent"]);
        self::assertCount(4, $this->mailer->sent);

        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Member::class)->findAll() as $member) {
            self::assertNotNull($member->getCardSentAt());
        }
    }

    public function testAnInterruptionMidwayLosesNothingAndNothingIsSentTwice(): void
    {
        // Simule le processus tué par l'hébergeur au milieu d'un lot (ce qui s'est passé le 6 novembre).
        $this->paidClubWithMembers(6);
        $sentWhenKilled = 0;

        try {
            $this->sender->deliver($this->sender->pending(100), function () use (&$sentWhenKilled): void {
                if (++$sentWhenKilled === 3) {
                    throw new \RuntimeException("processus tué");
                }
            });
        } catch (\RuntimeException) {
        }

        $this->entityManager->clear();
        $sent = array_filter($this->entityManager->getRepository(Member::class)->findAll(), static fn(Member $m) => $m->getCardSentAt() !== null);
        self::assertCount(3, $sent, "Les 3 cartes déjà parties sont enregistrées comme telles");
        self::assertCount(3, $this->sender->pending(100), "Il en reste 3 à envoyer, ni plus ni moins");

        $this->sender->deliver($this->sender->pending(100));
        self::assertCount(6, $this->mailer->sent, "Chaque carte est partie exactement une fois");
        self::assertCount(6, array_unique($this->mailer->recipients()));
    }

    public function testTimeLimitStopsCleanlyBeforeTheNextCard(): void
    {
        $this->paidClubWithMembers(5);

        $result = $this->sender->deliver($this->sender->pending(100), null, null, 0.0001);

        self::assertTrue($result["stopped"]);
        self::assertLessThan(5, $result["sent"]);
        self::assertSame(5 - $result["sent"], count($this->sender->pending(100)));
    }

    public function testFailureIsRecordedAndRetriedOnlyAfterTheDelay(): void
    {
        [, , $members] = $this->paidClubWithMembers(2);
        $this->mailer->failFor($members[0]->getEmail());

        $result = $this->sender->deliver($this->sender->pending(100));

        self::assertSame(1, $result["sent"]);
        self::assertSame(1, $result["failed"]);
        $this->entityManager->clear();
        $failed = $this->entityManager->find(Member::class, $members[0]->getId());
        self::assertNull($failed->getCardSentAt());
        self::assertSame(1, $failed->getCardAttempts());
        self::assertStringContainsString("Panne SMTP", $failed->getCardLastError());

        self::assertCount(0, $this->sender->pending(100), "Pas de nouvel essai tout de suite (évite de saturer le SMTP)");
        self::assertCount(1, $this->sender->pending(100, new \DateTimeImmutable("+11 minutes")), "Nouvel essai après le délai");
    }

    public function testAFailedCardDoesNotBlockTheFollowingOnesInTheQueue(): void
    {
        // Sans cela, 40 adresses invalides en tête de file bloqueraient indéfiniment toutes les cartes suivantes.
        [, , $members] = $this->paidClubWithMembers(4);
        $this->mailer->failFor($members[0]->getEmail());
        $this->sender->deliver($this->sender->pending(1));   // 1re carte en échec

        $next = $this->sender->pending(1);

        self::assertSame($members[1]->getId(), $next[0]["entity"]->getId());
    }

    public function testCardIsAbandonedAfterTooManyFailures(): void
    {
        [, , $members] = $this->paidClubWithMembers(1);
        $this->mailer->failFor($members[0]->getEmail());
        $sender = $this->senderWith(3, 0);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $sender->deliver($sender->pending(10));
        }

        self::assertSame(3, $this->mailer->attempts[$members[0]->getEmail()] / 2, "3 essais (chacun avec 1 nouvel essai SMTP interne), puis abandon");
        self::assertSame(["pending" => 0, "retrying" => 0, "abandoned" => 1], $sender->status());
        self::assertCount(0, $sender->pending(10, new \DateTimeImmutable("+1 day")), "Une carte abandonnée n'est plus retentée");
    }

    public function testTestRecipientReceivesTheCardsAndNothingIsMarked(): void
    {
        $this->paidClubWithMembers(2);

        $result = $this->sender->deliver($this->sender->pending(100), null, "moi@example.test");

        self::assertSame(2, $result["sent"]);
        self::assertSame(["moi@example.test", "moi@example.test"], $this->mailer->recipients(), "Aucune carte n'est envoyée aux vrais adhérents");
        self::assertCount(2, $this->sender->pending(100), "Rien n'est marqué comme envoyé");
    }

    public function testStatusCountsPendingRetryingAndAbandoned(): void
    {
        [, , $members] = $this->paidClubWithMembers(3);
        $members[1]->recordCardFailure("x");
        $members[2]->recordCardFailure("x");
        $members[2]->recordCardFailure("x");
        $members[2]->recordCardFailure("x");
        $members[2]->recordCardFailure("x");
        $members[2]->recordCardFailure("x");
        $this->entityManager->flush();

        self::assertSame(["pending" => 1, "retrying" => 1, "abandoned" => 1], $this->sender->status());
    }
}
