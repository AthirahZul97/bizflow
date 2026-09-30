<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Any authenticated user may list customers; the query itself is scoped to them.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may create customers for themselves.
     */
    public function create(User $user): bool
    {
        return true;
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
        return $this->owns($user, $customer);
    }

    /**
     * Determine whether the user can delete the customer.
     */
    public function delete(User $user, Customer $customer): Response
    {
        return $this->owns($user, $customer);
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
