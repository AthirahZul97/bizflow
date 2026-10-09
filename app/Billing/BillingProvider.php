<?php

namespace App\Billing;

use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * The boundary between BizFlow's commercial domain and a payment provider.
 *
 * A provider adapter (a future class under App\Billing\Providers\<Name>) only talks to the
 * provider and turns its answers into these value objects. It never writes subscriptions:
 * whoever calls it passes the result to SubscriptionService, the only writer. Nothing outside
 * an adapter may depend on one provider's names or payloads.
 *
 * Phase 2D ships only ManualBillingProvider; there is no checkout, no webhook route and no
 * payment table yet.
 */
interface BillingProvider
{
    /**
     * A short stable identifier ("manual"), stored with future payment records.
     */
    public function name(): string;

    /**
     * Whether a business can pay for a plan itself through this provider.
     */
    public function supportsCheckout(): bool;

    /**
     * Start a payment for a plan and say where to send the payer.
     *
     * @throws BillingProviderException when checkout is not available
     */
    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Verify that a webhook really came from the provider and translate it. Must reject
     * (throw) anything it can't authenticate; the caller handles duplicates by event id.
     *
     * @throws BillingProviderException when the request can't be authenticated or understood
     */
    public function parseWebhook(Request $request): BillingEvent;

    /**
     * Stop the provider charging a subscription again.
     */
    public function cancel(Subscription $subscription): void;

    /**
     * Refund (part of) a payment, by the provider's own payment reference and a decimal amount.
     *
     * @throws BillingProviderException when refunds are not available
     */
    public function refund(string $providerPaymentId, string $amount): void;
}
