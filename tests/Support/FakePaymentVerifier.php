<?php

namespace App\Tests\Support;

use App\Entity\Order;
use App\Service\PaymentVerifierInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Remplace Stripe dans les tests : le paiement est confirmé, sauf demande contraire.
 */
final class FakePaymentVerifier implements PaymentVerifierInterface
{
    public bool $paid = true;
    public int $calls = 0;

    public function assertPaid(?string $checkoutSessionId, Order $order): void
    {
        $this->calls++;
        if (!$this->paid) {
            throw new AccessDeniedException("Le paiement Stripe n'est pas confirmé.");
        }
    }
}
