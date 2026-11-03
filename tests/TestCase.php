<?php

namespace Tests;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign a user in for the tests (actingAs() calls this), the way a real sign-in leaves the session:
     * with the password fingerprint that RequirePasswordFingerprint insists on. Without it every
     * actingAs() request would be treated as an old session and logged out.
     *
     * @param  string|null  $guard
     * @return $this
     */
    public function be(UserContract $user, $guard = null)
    {
        parent::be($user, $guard);

        $auth = $this->app['auth'];

        if ($user->getAuthPassword() && method_exists($auth->guard($guard), 'hashPasswordForCookie')) {
            $this->withSession([
                'password_hash_'.$auth->getDefaultDriver() => $auth->guard($guard)->hashPasswordForCookie($user->getAuthPassword()),
            ]);
        }

        return $this;
    }

    /**
     * The user's (single) business.
     */
    protected function businessOf(User $user): Business
    {
        return $user->businesses()->sole();
    }

    /**
     * The owner of the business a record (or the business itself) belongs to.
     */
    protected function ownerOf(Business|Customer|Product|Invoice|Expense|ExpenseReceipt $record): User
    {
        $business = $record instanceof Business ? $record : $record->business;

        return $business->members()->wherePivot('role', BusinessRole::Owner->value)->sole();
    }
}
