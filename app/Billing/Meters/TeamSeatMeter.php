<?php

namespace App\Billing\Meters;

use App\Billing\UsageMeter;
use App\Models\Business;

/**
 * Memberships of the business. Pending invitations will count once they exist.
 */
class TeamSeatMeter implements UsageMeter
{
    public function used(Business $business): int
    {
        return $business->members()->count();
    }
}
