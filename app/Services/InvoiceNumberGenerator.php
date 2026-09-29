<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoiceNumberGenerator
{
    /**
     * Give the invoice its owner's next number, e.g. INV-00001. Does not save.
     *
     * Must run inside a transaction: the owner's row is locked so concurrent
     * issues for the same user are serialized. The unique (user_id,
     * invoice_sequence) and (user_id, invoice_number) indexes are the backstop.
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

        $user = User::query()->whereKey($invoice->user_id)->lockForUpdate()->firstOrFail();

        $sequence = (int) $user->invoices()->max('invoice_sequence') + 1;

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
