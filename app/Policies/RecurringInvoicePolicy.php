<?php

namespace App\Policies;

use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

/**
 * Ownership is checked first: another business's recurring invoice is always
 * reported as not found. Only then is the state checked, with a reason.
 */
class RecurringInvoicePolicy
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->owns($user, $recurringInvoice);
    }

    public function update(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->ownsWithState($user, $recurringInvoice, ! $recurringInvoice->isCancelled(),
            'Cancelled recurring invoices can’t be edited.');
    }

    public function pause(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->ownsWithState($user, $recurringInvoice, $recurringInvoice->isActive(),
            'Only active recurring invoices can be paused.');
    }

    public function resume(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->ownsWithState($user, $recurringInvoice, $recurringInvoice->isPaused(),
            'Only paused recurring invoices can be resumed.');
    }

    public function cancel(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->ownsWithState($user, $recurringInvoice, ! $recurringInvoice->isCancelled(),
            'This recurring invoice is already cancelled.');
    }

    /**
     * Only a schedule that has never generated an invoice can be deleted.
     */
    public function delete(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->ownsWithState($user, $recurringInvoice, ! $recurringInvoice->hasGenerated(),
            'This recurring invoice has generated invoices and can’t be deleted. Cancel it instead.');
    }

    /**
     * "Generate now" needs an active schedule; whether an occurrence is due is
     * checked again under the row lock by the service.
     */
    public function generate(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->ownsWithState($user, $recurringInvoice, $recurringInvoice->isActive(),
            'Only active recurring invoices can generate invoices.');
    }

    private function ownsWithState(User $user, RecurringInvoice $recurringInvoice, bool $allowed, string $message): Response
    {
        $ownership = $this->owns($user, $recurringInvoice);

        if ($ownership->denied()) {
            return $ownership;
        }

        return $allowed ? Response::allow() : Response::deny($message);
    }

    /**
     * Another business's recurring invoice is reported as not found, so record IDs cannot be probed.
     */
    private function owns(User $user, RecurringInvoice $recurringInvoice): Response
    {
        return $this->currentBusiness->owns($user, $recurringInvoice)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
