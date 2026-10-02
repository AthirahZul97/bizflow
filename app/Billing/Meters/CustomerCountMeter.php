<?php

namespace App\Billing\Meters;

use App\Billing\UsageMeter;
use App\Models\Business;

/**
 * Every customer the business has. Deleting a customer frees capacity.
 */
class CustomerCountMeter implements UsageMeter
{
    public function used(Business $business): int
    {
        return $business->customers()->count();
    }
}
