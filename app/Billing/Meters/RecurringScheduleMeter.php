<?php

namespace App\Billing\Meters;

use App\Billing\UsageMeter;
use App\Enums\RecurringInvoiceStatus;
use App\Models\Business;

/**
 * Active and paused schedules. Cancelled ones free capacity.
 */
class RecurringScheduleMeter implements UsageMeter
{
    public function used(Business $business): int
    {
        return $business->recurringInvoices()
            ->where('status', '!=', RecurringInvoiceStatus::Cancelled->value)
            ->count();
    }
}
