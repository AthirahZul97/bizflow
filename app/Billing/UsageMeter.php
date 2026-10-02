<?php

namespace App\Billing;

use App\Models\Business;

/**
 * Counts what a business currently uses of one limited entitlement, live from its own data.
 */
interface UsageMeter
{
    public function used(Business $business): int;
}
