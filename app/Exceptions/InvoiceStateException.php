<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Raised when an invoice is not in a state that allows the requested change,
 * e.g. when two requests race to issue the same draft.
 */
class InvoiceStateException extends RuntimeException
{
    /**
     * Send the user back with the reason instead of an error page.
     */
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
