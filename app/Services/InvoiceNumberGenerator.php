<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoiceNumberGenerator
{
    /**
     * Give the invoice its business's next number, e.g. INV-00001. Does not save.
     *
     * Must run inside a transaction: the business's row is locked so concurrent
     * issues within the same business are serialized (callers lock the invoice
     * row first, then the business row, always in that order). The unique
     * (business_id, invoice_sequence) and (business_id, invoice_number) indexes
     * are the backstop. Each business has its own sequence starting at 1.
     * Numbers are never reused because issued invoices cannot be deleted, and an
     * invoice that already has a number is refused.
     */
    public function assign(Invoice $invoice): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoice numbers must be assigned inside a database transaction.');
        }

        // An issued number never changes, so an invoice that already has one is never renumbered.
        if ($invoice->invoice_sequence !== null || $invoice->invoice_number !== null) {
            throw new LogicException('This invoice already has a number and cannot be renumbered.');
        }

        $business = Business::query()->whereKey($invoice->business_id)->lockForUpdate()->firstOrFail();

        // A locking read: under REPEATABLE READ a plain SELECT would use the snapshot taken
        // at the transaction's first read (before waiting for the lock above) and miss a
        // number another transaction has just committed.
        $sequence = (int) $business->invoices()->lockForUpdate()->max('invoice_sequence') + 1;

        $invoice->forceFill([
            'invoice_sequence' => $sequence,
            'invoice_number' => $this->format($sequence),
        ]);
    }

    /**
     * Format a sequence number using the configured prefix and padding.
     */
    public function format(int $sequence): string
    {
        return config('bizflow.invoice.number_prefix')
            .str_pad((string) $sequence, (int) config('bizflow.invoice.number_padding'), '0', STR_PAD_LEFT);
    }
}
