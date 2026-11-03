<?php

namespace App\Billing;

/**
 * A provider notification, already authenticated and normalised: the provider's own unique
 * event id (the idempotency key), a type, and the provider's reference to the payment or
 * subscription it concerns. Read-only.
 */
final readonly class BillingEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $providerReference = null,
        public array $data = [],
    ) {}
}
