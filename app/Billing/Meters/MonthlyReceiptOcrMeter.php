<?php

namespace App\Billing\Meters;

use App\Billing\UsageMeter;
use App\Models\Business;

/**
 * Receipt scans holding one unit of this calendar month's allowance (billing timezone).
 *
 * A receipt takes a unit when it is uploaded (or retried after a technical failure) and
 * counted_at is set, under the business row lock. The unit is released (counted_at cleared)
 * when the OCR fails technically or the receipt is discarded before it was extracted, so a
 * failure never permanently uses allowance. It is never counted twice: retrying an unreadable or
 * reviewed receipt keeps its one unit, and confirming uses none.
 */
class MonthlyReceiptOcrMeter implements UsageMeter
{
    public function used(Business $business): int
    {
        [$start, $end] = MonthlyIssuedInvoicesMeter::currentMonth();

        return $business->expenseReceipts()
            ->where('counted_at', '>=', $start)
            ->where('counted_at', '<', $end)
            ->count();
    }
}
