<?php

namespace App\Service;

use App\Entity\Order;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class StripePaymentVerifier implements PaymentVerifierInterface
{
    public function __construct(#[Autowire("%stripe_secret_key%")] private readonly string $secretKey)
    {
    }

    public function assertPaid(?string $checkoutSessionId, Order $order): void
    {
        if (!$checkoutSessionId) {
            throw new AccessDeniedException("Aucun paiement Stripe associé à cette commande.");
        }

        Stripe::setApiKey($this->secretKey);
        $checkout = StripeSession::retrieve($checkoutSessionId);

        if ($checkout->payment_status !== "paid") {
            throw new AccessDeniedException("Le paiement Stripe n'est pas confirmé.");
        }

        $metadataOrderId = $checkout->metadata["order_id"] ?? null;
        if ($metadataOrderId !== null && (string)$metadataOrderId !== (string)$order->getId()) {
            throw new AccessDeniedException("Le paiement ne correspond pas à cette commande.");
        }
    }
}
