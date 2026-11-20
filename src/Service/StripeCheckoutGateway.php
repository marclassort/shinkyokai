<?php

namespace App\Service;

use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StripeCheckoutGateway implements CheckoutGatewayInterface
{
    public function __construct(#[Autowire("%stripe_secret_key%")] private readonly string $secretKey)
    {
    }

    public function createSession(array $params): string
    {
        Stripe::setApiKey($this->secretKey);

        return StripeSession::create($params)->id;
    }
}
