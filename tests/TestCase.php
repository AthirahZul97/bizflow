<?php

namespace Tests;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
    protected function ownerOf(Business|Customer|Product|Invoice|Expense $record): User
    {
        $business = $record instanceof Business ? $record : $record->business;

        return $business->members()->wherePivot('role', BusinessRole::Owner->value)->sole();
    }
}
