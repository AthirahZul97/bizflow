<?php

namespace App\Policies;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class BusinessPolicy
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Only the owner may edit the business profile, and only for the business
     * they are currently working in.
     */
    public function update(User $user, Business $business): Response
    {
        if (! $business->is($this->currentBusiness->get())) {
            return Response::denyAsNotFound();
        }

        return $business->hasMember($user, BusinessRole::Owner)
            ? Response::allow()
            : Response::deny('Only the business owner can change the business profile.');
    }
}
