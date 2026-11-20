<?php

namespace App\Tests\Support;

use App\Service\CheckoutGatewayInterface;

/**
 * Remplace Stripe : mémorise ce qui SERAIT envoyé à Stripe, sans aucun appel réseau.
 */
final class FakeCheckoutGateway implements CheckoutGatewayInterface
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function createSession(array $params): string
    {
        $this->sessions[] = $params;

        return "cs_test_simulation_" . count($this->sessions);
    }
}
