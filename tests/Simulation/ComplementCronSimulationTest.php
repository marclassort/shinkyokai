<?php

namespace App\Tests\Simulation;

use App\Entity\Member;
use App\Entity\Order;
use App\Service\CardSender;

/**
 * SCÉNARIO 2 : même parcours, mais en mode « cron » (LICENCE_CARDS_MODE=cron) : l'inscription répond tout de suite,
 * puis les cartes partent par lots grâce à la commande app:licences:envoyer lancée chaque minute.
 */
class ComplementCronSimulationTest extends SimulationTestCase
{
    protected function cardsMode(): ?string
    {
        return "cron";
    }

    public function testComplementWithTheRealFileInCronMode(): void
    {
        $r = $this->report;
        $r->title("SCÉNARIO 2 — Même complément de 107 adhérents, en mode « cron » (inscription instantanée, cartes envoyées par lots)");
        $this->reportSandboxGuards();
        $this->seedProductionLikeState();
        $total = count($this->rows);

        $r->section("Parcours d'inscription (appels HTTP réels de l'application)");
        $this->http("POST", "/save-order-and-session", ["flow" => "complement", "club_number" => "SHIN0011"], ["csv" => $this->uploadCopy()]);
        $this->entityManager->clear();
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([], ["id" => "DESC"]);
        $this->http("POST", "/create-checkout-session");
        $started = microtime(true);
        $success = $this->http("GET", "/paiement-succes");
        $duration = microtime(true) - $started;
        $this->reportAndCleanStoredUploads();
        $r->check($success["status"] === 200, "La page de succès s'affiche", sprintf("%.2f s (en mode inline la même requête durait ≈ 18 s ici, et ≈ 6 minutes avec le SMTP réel)", $duration));
        $r->check($duration < 5, "L'inscription répond instantanément, quel que soit le nombre d'adhérents", sprintf("%.2f s pour %d adhérents", $duration, $total));
        $r->check(count($this->mailer->sent) === 0, "Aucun e-mail n'est envoyé depuis la requête web (plus de limite de temps de l'hébergeur)");

        $members = $this->membersOfOrder($order->getId());
        $r->check(count($members) === $total, sprintf("Les %d adhérents sont déjà tous enregistrés en base", $total));
        $this->verifyMembersMatchFile($members);
        $this->reportLicenceNumbers($members, 197);

        $sender = static::getContainer()->get(CardSender::class);
        $r->check($sender->status() === ["pending" => $total, "retrying" => 0, "abandoned" => 0], sprintf("%d cartes sont « en attente » dans la file d'envoi", $total));

        // ---- Essais sans risque ---------------------------------------------------------------------------------
        $r->section("Essais de la commande cron AVANT de l'activer (ce que vous feriez à la main)");
        $status = $this->runCommand("app:licences:envoyer", ["--status" => true]);
        $r->check(str_contains($status->getDisplay(), (string)$total), "--status : affiche l'état de la file sans rien envoyer");

        $dry = $this->runCommand("app:licences:envoyer", ["--dry-run" => true]);
        $r->check(str_contains($dry->getDisplay(), "SIMULATION") && count($this->mailer->sent) === 0, "--dry-run : liste les 40 prochaines cartes sans rien envoyer");

        $testMail = $this->runCommand("app:licences:envoyer", ["--test-recipient" => "vous@exemple.test"]);
        $testMails = $this->mailer->sent;
        $r->check(count($testMails) === 5 && array_unique($this->mailer->recipients()) === ["vous@exemple.test"], "--test-recipient : 5 cartes au maximum, toutes envoyées à VOTRE adresse, aucune aux adhérents", implode(", ", array_unique($this->mailer->recipients())));
        $r->check($sender->status()["pending"] === $total, "Mode test : aucune carte n'est marquée comme envoyée");
        $this->verifyMails($testMails, $this->byLicence(array_slice($members, 0, 5)), "mode test (5 premières cartes, destinataire de test)", "vous@exemple.test");

        // ---- Le cron, minute après minute -----------------------------------------------------------------------
        $r->section("Le cron en action : un passage par minute, 40 cartes au maximum par passage");
        $testCount = count($this->mailer->sent);
        $runs = [];
        for ($minute = 1; $minute <= 6; $minute++) {
            $before = count($this->mailer->sent);
            $started = microtime(true);
            $this->runCommand("app:licences:envoyer", []);
            $sent = count($this->mailer->sent) - $before;
            $runs[] = $sent;
            $r->info(sprintf("Minute %d : %d carte(s) envoyée(s) en %.1f s", $minute, $sent, microtime(true) - $started));
            if ($sent === 0) {
                break;
            }
        }
        $r->check(array_slice($runs, 0, 3) === [40, 40, 27] || array_sum($runs) === $total, "Les cartes partent par lots bornés puis la file se vide", implode(" + ", $runs));
        $r->check($sender->status() === ["pending" => 0, "retrying" => 0, "abandoned" => 0], "Plus rien en attente");

        $real = array_slice($this->mailer->sent, $testCount);
        $this->verifyMails($real, $this->byLicence($members), "envoi réel par le cron");

        $this->entityManager->clear();
        $marked = count(array_filter($this->entityManager->getRepository(Member::class)->findBy(["commande" => $order->getId()]), static fn(Member $m) => $m->getCardSentAt() !== null));
        $r->check($marked === $total, "Chaque adhérent est marqué « carte envoyée le … » (visible dans EasyAdmin)", "$marked/$total");
        $r->check(end($runs) === 0, "Le passage suivant n'envoie plus rien : aucune carte en double possible");

        $this->reportDatabaseState("État final de la base");
        $this->assertScenarioHasNoFailure();
    }
}
