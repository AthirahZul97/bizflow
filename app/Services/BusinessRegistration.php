<?php

namespace App\Services;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new account: the user, their business, the owner membership and the business's
 * one free trial, all in one transaction. If any step fails nothing is kept, so there is never
 * a user without a business or a business without its owner.
 */
class BusinessRegistration
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  array{name: string, email: string, password: string, business_name: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // The password is hashed by the User model's "hashed" cast.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $business = Business::create(['name' => $data['business_name']]);

            $business->members()->attach($user, ['role' => BusinessRole::Owner->value]);

            $this->subscriptions->startTrial($business, $user);

            return $user;
        });
    }
}
