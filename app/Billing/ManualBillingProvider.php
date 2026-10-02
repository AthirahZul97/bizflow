<?php

namespace App\Billing;

use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * The provider used while no payment provider exists. It never takes or fakes a payment:
 * paid plans are assigned by an operator with billing:assign. It exists so the commercial
 * domain works end to end without choosing a provider.
 */
class ManualBillingProvider implements BillingProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function supportsCheckout(): bool
    {
        return false;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        throw new BillingProviderException('Online payment is not available yet. Contact us to upgrade.');
    }

    public function parseWebhook(Request $request): BillingEvent
    {
        throw new BillingProviderException('The manual provider receives no webhooks.');
    }

    public function cancel(Subscription $subscription): void
    {
        // Nothing is charged automatically, so there is nothing to stop at a provider.
    }

    public function refund(string $providerPaymentId, string $amount): void
    {
        throw new BillingProviderException('Refunds are not available without a payment provider.');
    }
}
