<?php

namespace App\Service;

/**
 * Lignes de facturation envoyées à Stripe pour un panier (montants en centimes).
 * Les tarifs sont fixés ICI, jamais lus depuis le navigateur.
 */
class CheckoutLineItems
{
    public const CLUB_FEE_CENTS = 5000;
    public const MEMBER_FEE_CENTS = 500;
    public const INDIVIDUAL_FEE_CENTS = 1000;

    /**
     * @param array<string, mixed> $cart panier en session
     * @return list<array{price_data: array{currency: string, product_data: array{name: string}, unit_amount: int}, quantity: int}>
     */
    public function fromCart(array $cart): array
    {
        $lineItems = [];

        if (isset($cart["club"])) {
            $lineItems[] = $this->line("Inscription d'un club", self::CLUB_FEE_CENTS, 1);

            // Le nombre d'adhérents est celui compté côté serveur dans le fichier envoyé
            $memberCount = (int)($cart["club"]["members"] ?? 0);
            if ($memberCount > 0) {
                $lineItems[] = $this->line("Inscription d'un membre", self::MEMBER_FEE_CENTS, $memberCount);
            }
        }

        if (isset($cart["club_complement"])) {
            $lineItems[] = $this->line("Complément d'affiliation – membre", self::MEMBER_FEE_CENTS, (int)($cart["club_complement"]["members"] ?? 0));
        }

        // Produits classiques
        foreach ($cart as $key => $item) {
            if ($key !== "club" && isset($item["product"])) {
                $lineItems[] = $this->line($item["product"]->getName(), (int)round($item["product"]->getPrice() * 100), (int)$item["quantity"]);
            }
        }

        // Inscription individuelle : tarif fixe, jamais celui envoyé par le navigateur
        if (isset($cart["club_individuel"])) {
            $lineItems[] = $this->line("Inscription individuelle", self::INDIVIDUAL_FEE_CENTS, 1);
        }

        // Inscriptions arts culturels
        if (isset($cart["cultural_registration"])) {
            $lineItems[] = $this->line(
                $cart["cultural_registration"]["activity"] ?? "Inscription arts culturels",
                (int)round($cart["cultural_registration"]["price"] * 100),
                1
            );
        }

        return $lineItems;
    }

    /**
     * @param list<array{price_data: array{unit_amount: int}, quantity: int}> $lineItems
     */
    public static function totalCents(array $lineItems): int
    {
        return array_sum(array_map(static fn(array $l) => $l["price_data"]["unit_amount"] * $l["quantity"], $lineItems));
    }

    private function line(string $name, int $unitAmountCents, int $quantity): array
    {
        return [
            "price_data" => [
                "currency" => "eur",
                "product_data" => ["name" => $name],
                "unit_amount" => $unitAmountCents,
            ],
            "quantity" => $quantity,
        ];
    }
}
