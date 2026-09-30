<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

/**
 * Ownership is checked first: another user's invoice is always reported as
 * not found (404). For the owner, an action the invoice's status does not
 * allow is forbidden (403) with the reason.
 */
class InvoicePolicy
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Any authenticated user may list invoices; the query itself is scoped to them.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may create invoices for themselves.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): Response
    {
        return $this->owns($user, $invoice);
    }

    public function update(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, $invoice->status->isDraft(),
            'Only draft invoices can be edited.');
    }

    public function delete(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, $invoice->status->isDraft(),
            'Only draft invoices can be deleted. Cancel an issued invoice instead.');
    }

    public function issue(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, $this->canMove($invoice, InvoiceStatus::Draft, InvoiceStatus::Issued),
            'Only draft invoices can be issued.');
    }

    public function markPaid(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, $this->canMove($invoice, InvoiceStatus::Issued, InvoiceStatus::Paid),
            'Only issued invoices can be marked as paid.');
    }

    public function markUnpaid(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, $this->canMove($invoice, InvoiceStatus::Paid, InvoiceStatus::Issued),
            'Only paid invoices can be marked as unpaid.');
    }

    public function cancel(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, $this->canMove($invoice, InvoiceStatus::Issued, InvoiceStatus::Cancelled),
            'Only issued invoices can be cancelled.');
    }

    /**
     * Issued, paid and cancelled invoices have a PDF; drafts have no number yet.
     */
    public function downloadPdf(User $user, Invoice $invoice): Response
    {
        return $this->ownsWithStatus($user, $invoice, ! $invoice->status->isDraft(),
            'Draft invoices don’t have a PDF. Issue the invoice first.');
    }

    /**
     * The invoice is in the given state and InvoiceStatus allows the move.
     */
    /**
     * Issued and paid invoices can be emailed; drafts and cancelled invoices cannot.
     */
    public function sendEmail(User $user, Invoice $invoice): Response
    {
        if ($invoice->status->isCancelled()) {
            return $this->ownsWithStatus($user, $invoice, false, 'Cancelled invoices can’t be emailed.');
        }

        return $this->ownsWithStatus($user, $invoice, ! $invoice->status->isDraft(),
            'Draft invoices can’t be emailed. Issue the invoice first.');
    }

    private function canMove(Invoice $invoice, InvoiceStatus $from, InvoiceStatus $to): bool
    {
        return $invoice->status === $from && $from->canTransitionTo($to);
    }

    private function ownsWithStatus(User $user, Invoice $invoice, bool $allowed, string $message): Response
    {
        $ownership = $this->owns($user, $invoice);

        if ($ownership->denied()) {
            return $ownership;
        }

        return $allowed ? Response::allow() : Response::deny($message);
    }

    /**
     * Another business's invoice is reported as not found, so record IDs cannot be probed.
     */
    private function owns(User $user, Invoice $invoice): Response
    {
        return $this->currentBusiness->owns($user, $invoice)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
