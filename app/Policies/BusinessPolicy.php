<?php

namespace App\Policies;

use App\Billing\EntitlementService;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Policies\Concerns\ChecksSubscription;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class BusinessPolicy
{
    use ChecksSubscription;

    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Determine whether the user can edit the business profile.
     *
     * Only the owner of the current business, and only while the subscription allows writes.
     * Any other business is reported as not found, so business IDs cannot be probed.
     */
    public function update(User $user, Business $business): Response
    {
        if (! $business->is($this->currentBusiness->get())) {
            return Response::denyAsNotFound();
        }

        if (! $business->hasMember($user, BusinessRole::Owner)) {
            return Response::deny('Only the business owner can change the business profile.');
        }

        return $this->subscriptionAllowsWrites();
    }

    /**
     * Determine whether the user can see the business's subscription: any member.
     */
    public function viewBilling(User $user, Business $business): Response
    {
        if (! $business->is($this->currentBusiness->get())) {
            return Response::denyAsNotFound();
        }

        return $business->hasMember($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Determine whether the user can change the business's subscription: the owner only.
     * Deliberately not subject to the read-only rule: a read-only business must still be
     * able to choose a plan.
     */
    public function manageBilling(User $user, Business $business): Response
    {
        if (! $business->is($this->currentBusiness->get())) {
            return Response::denyAsNotFound();
        }

        return $business->hasMember($user, BusinessRole::Owner)
            ? Response::allow()
            : Response::deny('Only the business owner can manage billing.');
    }
}
