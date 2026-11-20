<?php

namespace App\Tests\Service;

use App\Service\CheckoutLineItems;
use PHPUnit\Framework\TestCase;

/**
 * Ce que Stripe facture : les montants sont calculés côté serveur, jamais lus depuis le navigateur.
 */
class CheckoutLineItemsTest extends TestCase
{
    public function testComplementOf107MembersIsBilled535Euros(): void
    {
        $items = (new CheckoutLineItems())->fromCart(["club_complement" => ["club_number" => "SHIN0011", "members" => 107]]);

        self::assertCount(1, $items);
        self::assertSame(500, $items[0]["price_data"]["unit_amount"]);
        self::assertSame(107, $items[0]["quantity"]);
        self::assertSame(53500, CheckoutLineItems::totalCents($items));
    }

    public function testNewClubIsBilled50EurosPlus5EurosPerMember(): void
    {
        $items = (new CheckoutLineItems())->fromCart(["club" => ["members" => 22]]);

        self::assertSame(16000, CheckoutLineItems::totalCents($items), "Commande 49 du 14/10 : 160 €");
        self::assertCount(2, $items);
    }

    public function testIndividualRegistrationUsesTheFixedTariffWhateverTheBrowserSends(): void
    {
        $items = (new CheckoutLineItems())->fromCart(["club_individuel" => ["price" => 0.01, "first_name" => "A"]]);

        self::assertSame(1000, CheckoutLineItems::totalCents($items));
    }

    public function testClubWithoutMembersIsBilledTheClubFeeOnly(): void
    {
        self::assertSame(5000, CheckoutLineItems::totalCents((new CheckoutLineItems())->fromCart(["club" => ["members" => 0]])));
    }

    public function testCulturalPriceIsConvertedToWholeCentsWithoutRoundingDrift(): void
    {
        $items = (new CheckoutLineItems())->fromCart(["cultural_registration" => ["activity" => "Sumi-e", "price" => 19.99]]);

        self::assertSame(1999, $items[0]["price_data"]["unit_amount"], "19,99 € × 100 = 1998.9999… ne doit pas devenir 1998");
        self::assertSame("Sumi-e", $items[0]["price_data"]["product_data"]["name"]);
    }

    public function testEmptyCartHasNothingToBill(): void
    {
        self::assertSame([], (new CheckoutLineItems())->fromCart([]));
    }
}
