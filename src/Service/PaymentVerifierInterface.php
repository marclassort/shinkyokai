<?php

namespace App\Service;

use App\Entity\Order;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Vérifie auprès du prestataire de paiement qu'une commande a bien été payée.
 */
interface PaymentVerifierInterface
{
    /**
     * @throws AccessDeniedException si le paiement n'est pas confirmé ou ne correspond pas à la commande
     */
    public function assertPaid(?string $checkoutSessionId, Order $order): void;
}
