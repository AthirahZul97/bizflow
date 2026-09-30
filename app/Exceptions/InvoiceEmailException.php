<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A send request that cannot be accepted right now (already in flight, rate
 * limited, no address). Shown to the user like an InvoiceStateException, and
 * not logged: it is an expected refusal, not an error.
 */
class InvoiceEmailException extends RuntimeException implements ShouldntReport
{
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
