<?php

namespace App\Billing;

use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Resolves what a business may do. It is the one door to access and entitlements: nothing
 * else reads a subscription's status or plan to decide anything.
 *
 * for() is memoized for the current request (or queued job) only: a different request, or a
 * subscription change through SubscriptionService, starts afresh. There is no other cache.
 * Code that must decide after taking the business row lock (EntitlementGuard, the services)
 * uses fresh() so it never trusts an earlier answer.
 */
class EntitlementService
{
    /** @var array<int, Entitlements> */
    private array $memo = [];

    private ?object $memoRequest = null;

    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly Container $container,
    ) {}

    public function for(Business $business): Entitlements
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        if ($this->memoRequest !== $request) {
            $this->memo = [];
            $this->memoRequest = $request;
        }

        return $this->memo[$business->getKey()] ??= $this->build($business);
    }

    /**
     * Re-read the subscription from the database, ignoring and replacing the memo.
     */
    public function fresh(Business $business): Entitlements
    {
        return $this->memo[$business->getKey()] = $this->build($business);
    }

    public function forget(Business $business): void
    {
        unset($this->memo[$business->getKey()]);
    }

    private function build(Business $business): Entitlements
    {
        // One query. The plan (and a pending plan) are loaded only if something asks for them.
        $subscription = $business->currentSubscription()->first();

        if ($subscription === null) {
            // Registration and the backfill always create one, so this is a broken account:
            // fail closed (read-only) rather than guess.
            Log::warning('Business has no current subscription.', ['business_id' => $business->getKey()]);
        }

        $now = CarbonImmutable::now();

        return new Entitlements($business, $this->resolver->resolve($subscription, $now), $now);
    }
}
