<?php

namespace App\Policies\Concerns;

use App\Enums\Entitlement;
use App\Exceptions\EntitlementException;
use Illuminate\Auth\Access\Response;

/**
 * Subscription rules for policies. A policy establishes tenant ownership FIRST (another
 * business's record is a 404) and only then asks these, so a refusal here is a 403 about the
 * caller's own subscription and never reveals anything about another business.
 *
 * These are the pre-flight answers behind buttons and forms. The services and the
 * subscription.writable middleware enforce the same rules authoritatively.
 *
 * The using policy must have $currentBusiness (App\Support\CurrentBusiness) and
 * $entitlements (App\Billing\EntitlementService).
 */
trait ChecksSubscription
{
    /**
     * Allowed when the subscription allows writes (full access or grace).
     */
    protected function subscriptionAllowsWrites(): Response
    {
        return $this->entitlements->for($this->currentBusiness->get())->canWrite()
            ? Response::allow()
            : Response::deny(EntitlementException::readOnly()->getMessage());
    }

    /**
     * Allowed when the plan includes the entitlement (and, for a limit, has room for one more).
     */
    protected function subscriptionAllows(Entitlement $entitlement): Response
    {
        $check = $this->entitlements->for($this->currentBusiness->get())->check($entitlement);

        return $check->allowed ? Response::allow() : Response::deny($check->message());
    }
}
