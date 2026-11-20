<?php

namespace App\Tests\Simulation;

use App\Entity\Club;
use App\Entity\Member;
use App\Entity\Order;
use App\Service\CardSender;
use App\Service\CheckoutLineItems;
use App\Service\LicenceNumberGenerator;

/**
 * SCÉNARIO 1 : un club envoie ce fichier via « Complément d'affiliation », avec le code actuel et l'envoi immédiat.
 * Parcours réel : formulaire → commande → paiement Stripe (simulé) → retour de paiement → base → PDF → e-mails.
 */
class ComplementInlineSimulationTest extends SimulationTestCase
{
    public function testComplementWithTheRealFileInImmediateSendingMode(): void
    {
        $r = $this->report;
        $r->title("SCÉNARIO 1 — Complément d'affiliation du club Shinkyobudo avec le fichier d'adhérents (envoi immédiat)");
        $this->reportSandboxGuards();
        $this->reportFile();

        $this->seedProductionLikeState();
        $this->reportDatabaseState("État initial de la base (reproduit la production au 20/11/2026)");
        $before = $this->snapshot();
        $generator = new LicenceNumberGenerator($this->entityManager);
        $highestBefore = $generator->highest();
        $clubsBefore = $this->entityManager->getRepository(Club::class)->count([]);
        $total = count($this->rows);

        // ---- Étape 1 : formulaire -------------------------------------------------------------------------------
        $r->section("Étape 1 — Formulaire « Complément d'affiliation » (appels HTTP réels de l'application)");
        $check = $this->http("POST", "/api/club/check-number", ["club_number" => "SHIN0011"]);
        $r->check($check["status"] === 200 && ($check["json"]["ok"] ?? false) && $check["json"]["club"]["name"] === "Shinkyobudo", "Le numéro de club SHIN0011 est reconnu", "Shinkyobudo");

        $count = $this->http("POST", "/count-members", [], ["csv" => $this->uploadCopy()]);
        $r->check(($count["json"]["memberCount"] ?? null) === $total, "Le fichier est accepté et compté côté serveur", sprintf("%s adhérents", $count["json"]["memberCount"] ?? "?"));

        $save = $this->http("POST", "/save-order-and-session", ["flow" => "complement", "club_number" => "SHIN0011"], ["csv" => $this->uploadCopy()]);
        $r->check($save["status"] === 200 && ($save["json"]["success"] ?? false), "Commande et panier créés");

        $this->entityManager->clear();
        /** @var Order $order */
        $order = $this->entityManager->getRepository(Order::class)->findOneBy([], ["id" => "DESC"]);
        $r->would(sprintf("Enregistrer la commande #%d : %s, statut « %s », %s €", $order->getId(), $order->getOrderType(), $order->getStatus(), number_format($order->getTotalAmount(), 2, ",", "")));
        $r->check($order->getOrderType() === "complement-affiliation" && $order->getStatus() === "en-cours", "La commande est « en-cours » tant que le paiement n'est pas confirmé");
        $r->check(abs($order->getTotalAmount() - $total * 5) < 0.001, sprintf("Montant de la commande = %d adhérents × 5 €", $total), number_format($order->getTotalAmount(), 2, ",", "") . " €");
        $membersBefore = $this->rowCount(Member::class);
        $r->check($membersBefore === 4, "À ce stade, aucun adhérent du fichier n'est encore enregistré (le paiement n'a pas eu lieu)", "4 adhérents préexistants");

        // ---- Étape 2 : paiement ---------------------------------------------------------------------------------
        $r->section("Étape 2 — Paiement Stripe (SIMULÉ : aucun appel à Stripe)");
        $session = $this->http("POST", "/create-checkout-session");
        $params = $this->checkout->sessions[0] ?? null;
        $r->check($session["status"] === 200 && $params !== null, "La session de paiement serait créée chez Stripe");
        if ($params !== null) {
            foreach ($params["line_items"] as $line) {
                $r->would(sprintf("Facturer « %s » : %d × %s €", $line["price_data"]["product_data"]["name"], $line["quantity"], number_format($line["price_data"]["unit_amount"] / 100, 2, ",", "")));
            }
            $billed = CheckoutLineItems::totalCents($params["line_items"]);
            $r->check($billed === (int)round($order->getTotalAmount() * 100), "Le montant facturé par Stripe = le montant de la commande", number_format($billed / 100, 2, ",", "") . " €");
            $r->check(($params["metadata"]["order_id"] ?? null) === (string)$order->getId(), "La session Stripe est liée à la commande #" . $order->getId() . " (anti-fraude au retour)");
            $r->info("Retour après paiement : " . $params["success_url"] . "   |   annulation : " . $params["cancel_url"]);
        }
        $r->info("Stripe confirmerait le paiement (simulé) puis renverrait le visiteur sur /paiement-succes.");

        // ---- Étape 3 : retour de paiement -----------------------------------------------------------------------
        $r->section("Étape 3 — Retour de paiement : vérification, enregistrement en base, génération et envoi des cartes");
        $started = microtime(true);
        $success = $this->http("GET", "/paiement-succes");
        $duration = microtime(true) - $started;
        $this->reportAndCleanStoredUploads();
        $r->check($success["status"] === 200, "La page de succès s'affiche", sprintf("requête complète, envoi simulé compris : %.1f s (en production l'envoi des cartes a lieu APRÈS la réponse au visiteur)", $duration));
        $r->check($this->payment->calls === 1, "Le paiement est vérifié auprès de Stripe avant tout enregistrement (appel unique)");

        $members = $this->membersOfOrder($order->getId());
        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $order->getId());
        $r->section("Résultat en base de données");
        $r->would(sprintf("Enregistrer %d adhérents dans le club Shinkyobudo, saison 2026-2027, rattachés à la commande #%d", count($members), $order->getId()));
        $r->check($order->getStatus() === "payee", "Commande passée à « payee »");
        $r->check($order->getClub()?->getClubNumber() === "SHIN0011", "Commande rattachée au club SHIN0011");
        $r->check(count($members) === $total, sprintf("%d adhérents créés (autant que de lignes du fichier)", $total), (string)count($members));
        $this->verifyMembersMatchFile($members);
        $this->reportLicenceNumbers($members, $highestBefore + 1);
        $r->check(count(array_filter($members, static fn(Member $m) => $m->getClub()?->getClubNumber() === "SHIN0011" && $m->getSportSeason() === "2026-2027")) === $total, "Tous les adhérents sont dans le club SHIN0011, saison 2026-2027");
        $r->check($this->rowCount(Club::class) === $clubsBefore, "Aucun club créé par un complément");
        $r->check(count(array_filter($members, static fn(Member $m) => $m->getCardSentAt() !== null)) === $total, "Chaque carte envoyée est marquée « envoyée » en base (aucun renvoi possible)");
        $r->check(static::getContainer()->get(CardSender::class)->status() === ["pending" => 0, "retrying" => 0, "abandoned" => 0], "Plus aucune carte en attente");

        $mailDetails = $this->verifyMails($this->mailer->sent, $this->byLicence($members), "envoi immédiat (mode inline)");
        $r->info(sprintf("Temps moyen de génération d'un PDF + préparation : %.2f s par carte (l'envoi SMTP réel s'y ajoute en production : c'est lui qui a fait dépasser la limite de l'hébergeur).", $duration / max(1, $total)));

        $after = $this->snapshot();
        $this->reportDatabaseChanges("Modifications de la base de données (comparaison avant / après le paiement)", $before, $after, $mailDetails);
        $this->reportPerRowJournal("Scénario 1 — complément avec envoi immédiat", $members, $before, $after, $mailDetails);

        // ---- Étape 4 : rechargement -----------------------------------------------------------------------------
        $r->section("Étape 4 — Le visiteur recharge la page de succès (F5)");
        $mailsBefore = count($this->mailer->sent);
        $reload = $this->http("GET", "/paiement-succes");
        $r->check($reload["status"] < 500, "Pas d'erreur serveur au rechargement", "HTTP " . $reload["status"]);
        $r->check(count($this->mailer->sent) === $mailsBefore, "Aucun e-mail supplémentaire");
        $r->check($this->rowCount(Member::class) === $membersBefore + $total, "Aucun adhérent supplémentaire");

        $this->reportDatabaseState("État final de la base");
        $this->assertScenarioHasNoFailure();
    }
}
