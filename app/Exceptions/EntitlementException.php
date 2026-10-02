<?php

namespace App\Exceptions;

use App\Billing\EntitlementCheck;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Raised when the business's subscription doesn't allow an action: read-only access, a
 * feature its plan lacks, or a usage limit reached.
 */
class EntitlementException extends RuntimeException
{
    public function __construct(public readonly ?EntitlementCheck $check, string $message)
    {
        parent::__construct($message);
    }

    public static function denied(EntitlementCheck $check): self
    {
        return new self($check, $check->message());
    }

    /**
     * For work that needs write access but is not about one entitlement (e.g. the scheduler).
     */
    public static function readOnly(): self
    {
        return new self(null, 'Your subscription is read-only, so changes are turned off. Review your plan to continue.');
    }

    /**
     * Send the user back with the reason instead of an error page.
     */
    public function render(): RedirectResponse
    {
        return back()->withInput()->with('error', $this->getMessage());
    }
}
