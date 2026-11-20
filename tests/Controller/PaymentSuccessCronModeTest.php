<?php

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\Member;
use App\Service\CardSender;
use App\Tests\Support\MemberFile;
use App\Tests\Support\PaymentFlowTestCase;

/**
 * Mode « cron » (LICENCE_CARDS_MODE=cron) : l'inscription répond tout de suite et n'envoie plus les cartes elle-même ;
 * elles partent ensuite par `app:licences:envoyer`.
 */
class PaymentSuccessCronModeTest extends PaymentFlowTestCase
{
    protected function cardsMode(): ?string
    {
        return "cron";
    }

    public function testLargeLotIsRegisteredInstantlyWithoutSendingAnythingFromTheRequest(): void
    {
        $this->startCheckout("inscription-club", $this->clubCart(107), 585.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(107, $this->rowCount(Member::class));
        self::assertSame(1, $this->rowCount(Club::class));
        self::assertCount(0, $this->mailer->sent, "La requête web n'envoie aucune carte : plus de limite de temps de l'hébergeur");
        self::assertSame(108, static::getContainer()->get(CardSender::class)->status()["pending"], "Club + 107 adhérents en attente");
    }

    public function testTheCronThenDeliversEveryCardExactlyOnceAcrossSeveralRuns(): void
    {
        $this->startCheckout("inscription-club", $this->clubCart(25), 175.0);
        $this->client->request("GET", "/paiement-succes");
        $sender = static::getContainer()->get(CardSender::class);

        // Le cron passe plusieurs fois, par lots de 10 (comme avec --limit=10).
        $runs = 0;
        while (($items = $sender->pending(10)) && $runs++ < 10) {
            $sender->deliver($items);
        }

        self::assertSame(3, $runs, "26 cartes en 3 passages de 10 maximum");
        self::assertCount(26, $this->mailer->sent);
        self::assertCount(26, array_unique(array_map(
            static fn($m) => $m->getTo()[0]->getAddress() . $m->getAttachments()[0]->getFilename() . spl_object_id($m),
            $this->mailer->sent
        )));
        self::assertSame(["pending" => 0, "retrying" => 0, "abandoned" => 0], $sender->status());
    }

    public function testCulturalConfirmationStillGoesOutImmediately(): void
    {
        // Un simple e-mail sans PDF : inutile de le différer.
        $this->startCheckout("inscription-arts-culturels", ["cultural_registration" => [
            "activity" => "Sumi-e", "price" => 20, "first_name" => "Claire", "last_name" => "Exemple",
            "birth_date" => "01/02/1990", "sex" => "Féminin", "email" => "claire@example.test",
        ]], 20.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertResponseIsSuccessful();
        self::assertSame(["claire@example.test"], $this->mailer->recipients());
    }

    public function testIndividualCardWaitsForTheCronInThisMode(): void
    {
        $this->startCheckout("inscription-individuelle", ["club_individuel" => [
            "price" => 10, "first_name" => "Jean", "last_name" => "Dupont", "birth_date" => "12/03/1985",
            "sex" => "Masculin", "email" => "jean@example.test",
        ]], 10.0);

        $this->client->request("GET", "/paiement-succes");

        self::assertCount(0, $this->mailer->sent);
        $sender = static::getContainer()->get(CardSender::class);
        self::assertSame(1, $sender->status()["pending"]);
        $sender->deliver($sender->pending(10));
        self::assertSame(["jean@example.test"], $this->mailer->recipients());
    }
}
