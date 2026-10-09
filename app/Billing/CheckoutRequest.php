<?php

namespace App\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;

/**
 * What a provider needs to start a payment. The business comes from the current business,
 * never from request input. Read-only.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public Business $business,
        public Plan $plan,
        public ?User $payer = null,
    ) {}
}
