<?php

namespace App\Billing;

/**
 * Where to send the payer, and the provider's own reference for the attempt. Read-only.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $url,
        public string $providerReference,
    ) {}
}
