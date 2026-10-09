<?php

namespace App\Billing\Meters;

use App\Billing\UsageMeter;
use App\Models\Business;

/**
 * Every product and service, active or not, so deactivating one never frees capacity.
 * Deleting one (where the application allows it) does.
 */
class ProductCountMeter implements UsageMeter
{
    public function used(Business $business): int
    {
        return $business->products()->count();
    }
}
