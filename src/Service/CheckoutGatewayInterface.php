<?php

namespace App\Service;

/**
 * Création d'une session de paiement chez le prestataire (Stripe Checkout).
 */
interface CheckoutGatewayInterface
{
    /**
     * @param array<string, mixed> $params paramètres de la session (lignes, URL de retour, référence de commande...)
     * @return string identifiant de la session de paiement
     */
    public function createSession(array $params): string;
}
