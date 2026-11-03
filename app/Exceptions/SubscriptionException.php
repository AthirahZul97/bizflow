<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Raised when a subscription change isn't allowed: a plan that can't be chosen, a trial
 * already used, a stale or impossible transition.
 */
class SubscriptionException extends RuntimeException
{
    /**
     * Send the user back with the reason instead of an error page.
     */
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
