<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A recurring invoice action that cannot be done in its current state, or a
 * generation that failed for a reason the owner can fix (for example an inactive
 * product). Shown to the user like an InvoiceStateException, and not logged: it
 * is an expected refusal, not an error.
 */
class RecurringInvoiceException extends RuntimeException implements ShouldntReport
{
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
