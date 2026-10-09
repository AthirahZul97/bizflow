<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A receipt action that cannot be accepted right now (wrong state, no file, too many
 * attempts). Shown to the user like an InvoiceEmailException, and not logged: it is an
 * expected refusal, not an error.
 */
class ExpenseReceiptException extends RuntimeException implements ShouldntReport
{
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
