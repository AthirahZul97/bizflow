<?php

namespace App\Billing\Meters;

use App\Billing\UsageMeter;
use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * Invoices issued in the current calendar month of the billing timezone.
 *
 * Uses the system issuance timestamp (issued_at), never the user-editable issue_date.
 * Cancelled invoices still count (their number was consumed); drafts, including
 * recurring-generated ones, do not count until they are issued.
 */
class MonthlyIssuedInvoicesMeter implements UsageMeter
{
    public function used(Business $business): int
    {
        [$start, $end] = self::currentMonth();

        return $business->invoices()
            ->whereNotNull('issued_at')
            ->where('issued_at', '>=', $start)
            ->where('issued_at', '<', $end)
            ->count();
    }

    /**
     * The month's bounds, converted to the application timezone the timestamps are stored in.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function currentMonth(?CarbonImmutable $now = null): array
    {
        $zone = config('billing.timezone');
        $start = ($now ?? CarbonImmutable::now())->setTimezone($zone)->startOfMonth();
        $storage = config('app.timezone');

        return [$start->setTimezone($storage), $start->addMonth()->setTimezone($storage)];
    }
}
