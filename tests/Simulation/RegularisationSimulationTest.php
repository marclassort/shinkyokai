<?php

namespace App\Tests\Simulation;

use App\Entity\Member;
use App\Entity\Order;
use App\Service\CardSender;
use App\Service\LicenceNumberGenerator;
use App\Service\NameFormatter;

/**
 * SCÉNARIO 3 : la régularisation de la commande #52 du 6 novembre, exactement comme elle serait faite en production
 * (simulation, puis application sans envoi, puis essai du cron, puis envoi réel).
 */
class RegularisationSimulationTest extends SimulationTestCase
{
    private const ALREADY_SENT = 56;
    private const FIRST_LICENCE = 195;

    public function testRegularisationOfOrder52WithTheRealFile(): void
    {
        $r = $this->report;
        $r->title("SCÉNARIO 3 — Régularisation de la commande #52 (complément Shinkyobudo du 6 novembre)");
        $this->reportSandboxGuards();
        $this->reportFile();

        $state = $this->seedProductionLikeState();
        $order52Id = $state["order52"]->getId();
        $this->reportDatabaseState("État initial de la base (reproduit la production : commande #52 « en-cours », SIMULE-A en SKK0195/SKK0196)");
        $total = count($this->rows);
        $alreadySent = min(self::ALREADY_SENT, $total);
        $membersBefore = $this->rowCount(Member::class);
        $s0 = $this->snapshot();

        // Repères à comparer, à l'œil, avec les cartes déjà envoyées le 6 novembre (affichés ici seulement, jamais écrits dans le dépôt)
        if ($total >= self::ALREADY_SENT) {
            $r->info(sprintf("Repères : ligne 1 = %s (attendu SKK%04d) ; ligne %d = %s (attendu SKK%04d).", $this->who($this->rows[0]), self::FIRST_LICENCE,
                self::ALREADY_SENT, $this->who($this->rows[self::ALREADY_SENT - 1]), self::FIRST_LICENCE + self::ALREADY_SENT - 1));
        }

        // Lignes dont le prénom et le nom semblent inversés dans le fichier : corrigées dans la base (--swap-names), sans renvoyer leur carte.
        $swapRows = $this->suspectedSwappedRows();
        foreach ($swapRows as $rowNumber) {
            $original = $this->rows[$rowNumber - 1];
            $r->info(sprintf("Correction demandée : ligne %d, nom et prénom inversés dans le fichier (--swap-names=%d). Sa carte est déjà partie et ne sera pas renvoyée.", $rowNumber, $rowNumber));
            // (le prénom brut tout en capitales a déjà été mis en forme à la lecture : le nom de famille repasse en capitales)
            [$this->rows[$rowNumber - 1]["first_name"], $this->rows[$rowNumber - 1]["last_name"]] = [NameFormatter::firstName($original["last_name"]), mb_strtoupper($original["first_name"])];
        }

        $args = ["order" => $order52Id, "file" => $this->sourceFile, "--club-number" => "SHIN0011", "--first-licence" => self::FIRST_LICENCE, "--already-sent" => self::ALREADY_SENT] + ($swapRows ? ["--swap-names" => implode(",", $swapRows)] : []);

        // ---- Étape 1 : simulation -------------------------------------------------------------------------------
        $r->section("Étape 1 — Simulation de la régularisation (ne modifie rien, n'envoie rien)");
        $dry = $this->runCommand("app:licences:regulariser", $args);
        $display = $dry->getDisplay();
        $last = self::FIRST_LICENCE + $total - 1;
        $r->check(str_contains($display, sprintf("SKK%04d → SKK%04d", self::FIRST_LICENCE, $last)), sprintf("Le plan annonce les numéros SKK%04d → SKK%04d", self::FIRST_LICENCE, $last));
        $r->check(str_contains($display, sprintf("SKK%04d", $last + 1)) && str_contains($display, sprintf("SKK%04d", $last + 2)), sprintf("Le plan renumérote les deux SIMULE-A en SKK%04d et SKK%04d", $last + 1, $last + 2));
        $r->check(substr_count($display, "oui") >= 2 && !str_contains($display, "NON (pas d'e-mail"), "Les deux cartes de remplacement sont bien dans la file d'envoi");
        $this->entityManager->clear();
        $r->check($this->rowCount(Member::class) === $membersBefore && $this->entityManager->find(Order::class, $order52Id)->getStatus() === "en-cours", "Rien n'a été modifié en base");
        $r->check(count($this->mailer->sent) === 0, "Aucun e-mail envoyé");

        // ---- Étape 2 : application sans envoi -------------------------------------------------------------------
        $r->section("Étape 2 — Application de la régularisation, SANS envoi (--no-mail)");
        $exec = $this->runCommand("app:licences:regulariser", $args + ["--already-sent-at" => "2026-11-06 22:03", "--no-mail" => true, "--execute" => true]);
        $r->check($exec->getStatusCode() === 0, "La commande se termine sans erreur");
        $r->check(count($this->mailer->sent) === 0, "Aucun e-mail envoyé");

        $members = $this->membersOfOrder($order52Id);
        $r->would(sprintf("Enregistrer %d adhérents, passer la commande #%d à « payee » et la rattacher au club SHIN0011", count($members), $order52Id));
        $r->check(count($members) === $total, sprintf("%d adhérents enregistrés pour la commande #%d", $total, $order52Id));
        $this->verifyMembersMatchFile($members);
        $this->reportLicenceNumbers($members, self::FIRST_LICENCE);
        $r->check($members[0]->getLicenceNumber() === "SKK0195", "La ligne 1 garde SKK0195, le numéro de sa carte déjà envoyée");
        if ($total > self::ALREADY_SENT) {
            $r->check($members[self::ALREADY_SENT - 1]->getLicenceNumber() === LicenceNumberGenerator::format(self::FIRST_LICENCE + self::ALREADY_SENT - 1), sprintf("La ligne %d garde SKK%04d, le numéro de sa carte déjà envoyée", self::ALREADY_SENT, self::FIRST_LICENCE + self::ALREADY_SENT - 1));
            $r->check($members[self::ALREADY_SENT]->getLicenceNumber() === LicenceNumberGenerator::format(self::FIRST_LICENCE + self::ALREADY_SENT), sprintf("La ligne %d reçoit SKK%04d : la suite des %d premières", self::ALREADY_SENT + 1, self::FIRST_LICENCE + self::ALREADY_SENT, self::ALREADY_SENT));
        }
        foreach ($swapRows as $rowNumber) {
            $member = $members[$rowNumber - 1];
            $expected = $this->rows[$rowNumber - 1];
            $r->check($member->getFirstName() === $expected["first_name"] && $member->getLastName() === $expected["last_name"], sprintf("Ligne %d : enregistrée avec le prénom et le nom dans le bon ordre", $rowNumber), $member->getFirstName() . " / " . $member->getLastName());
            $r->check($member->getCardSentAt() !== null, sprintf("La carte déjà envoyée de la ligne %d n'est PAS renvoyée (marquée « envoyée le 06/11/2026 22:03 »)", $rowNumber));
        }

        $s1 = $this->snapshot();
        $this->reportDatabaseChanges("Modifications de la base de données par la régularisation (avant / après, sans aucun envoi)", $s0, $s1);

        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $order52Id);
        $r->check($order->getStatus() === "payee" && $order->getClub()?->getClubNumber() === "SHIN0011" && abs($order->getTotalAmount() - 535.0) < 0.001, "Commande #52 : « payee », rattachée à SHIN0011, montant encaissé inchangé (535,00 €)");

        $repo = $this->entityManager->getRepository(Member::class);
        $remplace1 = $repo->findOneBy(["lastName" => "SIMULE-A"]);
        $remplace2 = $repo->findOneBy(["lastName" => "SIMULE-B"]);
        $r->check($remplace1->getLicenceNumber() === LicenceNumberGenerator::format($last + 1) && $remplace2->getLicenceNumber() === LicenceNumberGenerator::format($last + 2), "Les deux adhérents arrivés depuis ont les numéros les plus élevés", sprintf("%s et %s", $remplace1->getLicenceNumber(), $remplace2->getLicenceNumber()));
        $r->check($remplace1->getCardReplaces() === "SKK0195" && $remplace2->getCardReplaces() === "SKK0196", "Leur ancien numéro est mémorisé pour l'e-mail de remplacement");
        $existant = $repo->findOneBy(["lastName" => "SIMULE-C"]);
        $r->check($existant->getLicenceNumber() === "SKK0141", "Chloe SIMULE-C (déjà affilié) n'est pas modifié");

        $sentAt = array_map(static fn(Member $m) => $m->getCardSentAt()?->format("Y-m-d H:i"), $members);
        $r->check(count(array_filter(array_slice($sentAt, 0, $alreadySent), static fn($d) => $d === "2026-11-06 22:03")) === $alreadySent, sprintf("Les %d premiers sont enregistrés « carte envoyée le 06/11/2026 22:03 » : ils ne recevront rien", $alreadySent));
        $r->check(count(array_filter(array_slice($sentAt, $alreadySent), static fn($d) => $d === null)) === $total - $alreadySent, sprintf("Les %d suivants restent « en attente »", $total - $alreadySent));

        // ---- Étape 3 : essais ---------------------------------------------------------------------------------
        $r->section("Étape 3 — Essai de l'envoi AVANT d'activer le cron");
        $sender = static::getContainer()->get(CardSender::class);
        $expectedPending = $total - $alreadySent + 2;
        $status = $this->runCommand("app:licences:envoyer", ["--status" => true]);
        $r->check($sender->status() === ["pending" => $expectedPending, "retrying" => 0, "abandoned" => 0], sprintf("%d cartes en attente (%d nouvelles + 2 remplacements)", $expectedPending, $total - $alreadySent));
        $dry = $this->runCommand("app:licences:envoyer", ["--dry-run" => true, "--limit" => 100]);
        $r->check(str_contains($dry->getDisplay(), "SIMULATION") && count($this->mailer->sent) === 0, "--dry-run : la liste est affichée, rien n'est envoyé");

        $this->runCommand("app:licences:envoyer", ["--test-recipient" => "vous@exemple.test"]);
        $testMails = $this->mailer->sent;
        $r->check(count($testMails) === 5 && array_unique($this->mailer->recipients()) === ["vous@exemple.test"], "--test-recipient : 5 cartes envoyées à VOTRE adresse uniquement", implode(", ", array_unique($this->mailer->recipients())));
        $r->check($sender->status()["pending"] === $expectedPending, "Mode test : rien n'est marqué comme envoyé");
        // La file suit l'ordre d'enregistrement : les deux SIMULE-A (plus anciens en base) passent d'abord, puis le lot.
        $this->entityManager->clear();
        $firstFive = array_merge(
            [$this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-A"]), $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-B"])],
            array_slice($this->membersOfOrder($order52Id), $alreadySent, 3)
        );
        $testDetails = $this->verifyMails($testMails, $this->byLicence($firstFive), "mode test (les 5 premières cartes de la file : 2 remplacements puis le lot, destinataire de test)", "vous@exemple.test");

        // ---- Étape 4 : envoi réel ---------------------------------------------------------------------------------
        $r->section("Étape 4 — Envoi réel par lots de 40 (ce que ferait le cron)");
        $testCount = count($this->mailer->sent);
        $runs = [];
        for ($i = 1; $i <= 5; $i++) {
            $before = count($this->mailer->sent);
            $this->runCommand("app:licences:envoyer", []);
            $runs[] = count($this->mailer->sent) - $before;
            if (end($runs) === 0) {
                break;
            }
        }
        $r->info("Cartes envoyées à chaque passage : " . implode(" + ", $runs));
        $real = array_slice($this->mailer->sent, $testCount);
        $r->check(count($real) === $expectedPending, sprintf("%d e-mails réels (%d adhérents + 2 remplacements) — pas un de plus", $expectedPending, $total - $alreadySent), (string)count($real));

        $this->entityManager->clear();
        $expected = $this->byLicence(array_merge(
            array_slice($this->membersOfOrder($order52Id), $alreadySent),
            [$repo = $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-A"]), $this->entityManager->getRepository(Member::class)->findOneBy(["lastName" => "SIMULE-B"])]
        ));
        $details = $this->verifyMails($real, $expected, "envoi réel : lignes " . ($alreadySent + 1) . " à $total + 2 cartes de remplacement");

        $s2 = $this->snapshot();
        $this->reportDatabaseChanges("Modifications de la base de données par l'envoi des cartes (cartes marquées « envoyées »)", $s1, $s2, $details);
        $this->reportPerRowJournal("Scénario 3 — régularisation de la commande #52", $this->membersOfOrder($order52Id), $s0, $s2, $details, $testDetails);

        $replacements = array_filter($real, static fn($m) => ($m->getContext()["replaces"] ?? null) !== null);
        $olds = array_map(static fn($m) => $m->getContext()["replaces"], array_values($replacements));
        sort($olds);
        $r->check($olds === ["SKK0195", "SKK0196"], "Exactement 2 cartes de remplacement, pour les anciens numéros SKK0195 et SKK0196", implode(", ", $olds));
        $r->check($sender->status() === ["pending" => 0, "retrying" => 0, "abandoned" => 0], "Plus rien en attente");

        // ---- Étape 5 : on relance tout ------------------------------------------------------------------------------
        $r->section("Étape 5 — Par erreur, on relance la régularisation et le cron");
        $mailsBefore = count($this->mailer->sent);
        $again = $this->runCommand("app:licences:regulariser", $args + ["--execute" => true]);
        $r->check(str_contains($again->getDisplay(), "déjà « payee »") && $this->rowCount(Member::class) === $membersBefore + $total, "La régularisation refuse de rejouer : aucun adhérent en double");
        $this->runCommand("app:licences:envoyer", []);
        $r->check(count($this->mailer->sent) === $mailsBefore, "Le cron n'envoie plus rien : aucune carte en double");

        $this->reportDatabaseState("État final de la base");
        $this->assertScenarioHasNoFailure();
    }
}
