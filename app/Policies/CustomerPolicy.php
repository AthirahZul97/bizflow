<?php

namespace App\Policies;

use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksSubscription;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    use ChecksSubscription;

    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Any authenticated user may list customers; the query itself is scoped to them.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may create customers for themselves, while the subscription
     * allows writes and the plan has room for one more.
     */
    public function create(User $user): Response
    {
        return $this->subscriptionAllows(Entitlement::Customers);
    }

    /**
     * Determine whether the user can view the customer.
     */
    public function view(User $user, Customer $customer): Response
    {
        return $this->owns($user, $customer);
    }

    /**
     * Determine whether the user can update the customer.
     */
    public function update(User $user, Customer $customer): Response
    {
        return $this->ownsForWrite($user, $customer);
    }

    /**
     * Determine whether the user can delete the customer.
     */
    public function delete(User $user, Customer $customer): Response
    {
        return $this->ownsForWrite($user, $customer);
    }

    /**
     * Ownership first (404 for another business), then the subscription must allow writes (403).
     */
    private function ownsForWrite(User $user, Customer $customer): Response
    {
        $ownership = $this->owns($user, $customer);

        return $ownership->denied() ? $ownership : $this->subscriptionAllowsWrites();
    }

    /**
     * Another business's customer is reported as not found, so record IDs cannot be probed.
     */
    private function owns(User $user, Customer $customer): Response
    {
        return $this->currentBusiness->owns($user, $customer)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
