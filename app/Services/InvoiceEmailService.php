<?php

namespace App\Services;

use App\Enums\InvoiceEmailStatus;
use App\Exceptions\InvoiceEmailException;
use App\Jobs\SendInvoiceEmail;
use App\Mail\InvoiceMail;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Queues invoice emails and moves their send-history rows through their states.
 *
 * Guarantees:
 * - Only issued and paid invoices are emailed, checked when queued and again
 *   when the job claims the row.
 * - At most one send per invoice is in flight (queued or sending), enforced
 *   under the invoice's row lock.
 * - A row is sent at most once: only the conditional queued -> sending update
 *   in claim() leads to sending, and a row that has left "queued" can never
 *   return to it except from "sending" after a failed attempt.
 * - A queued row stuck for STUCK_AFTER_MINUTES can be superseded by a new
 *   request; it is first moved to "skipped" with the same conditional update,
 *   so its job, if it ever runs, finds nothing to send. A "sending" row is
 *   never superseded: its SMTP call may already be under way.
 *
 * The recipient is only ever the address copied onto the invoice or the
 * customer's current address, never free text. Ownership comes from the
 * invoice's business; requested_by is audit metadata only.
 */
class InvoiceEmailService
{
    /**
     * A queued row older than this is treated as stuck and may be superseded.
     */
    public const STUCK_AFTER_MINUTES = 15;

    public const BUSINESS_SENDS_PER_HOUR = 30;

    public const INVOICE_SENDS_PER_DAY = 5;

    public const INVOICE_COOLDOWN_SECONDS = 60;

    /**
     * Attempts for these short transactions if MySQL picks them as a deadlock victim.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * The addresses the invoice may be sent to: the one copied onto the invoice
     * and, when different, the customer's current one. Missing ones are null.
     *
     * @return array{invoice: string|null, customer: string|null}
     */
    public function recipientOptions(Business $business, Invoice $invoice): array
    {
        $this->ensureBelongsTo($invoice, $business);

        $invoiceEmail = $this->normalize($invoice->customer_email);
        $customerEmail = $this->normalize($business->customers()->whereKey($invoice->customer_id)->value('email'));

        return [
            InvoiceEmail::SOURCE_INVOICE => $invoiceEmail,
            InvoiceEmail::SOURCE_CUSTOMER => $customerEmail !== $invoiceEmail ? $customerEmail : null,
        ];
    }

    /**
     * Accept a request to email the invoice, and queue the job once the
     * transaction has committed.
     */
    public function queue(Business $business, Invoice $invoice, ?User $requester, string $source): InvoiceEmail
    {
        $this->ensureBelongsTo($invoice, $business);

        $email = DB::transaction(function () use ($business, $invoice, $requester, $source) {
            // Serializes every send request for this invoice.
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureBelongsTo($invoice, $business);
            $invoice->setRelation('business', $business);

            if (! $this->isSendable($invoice)) {
                throw new InvoiceEmailException($invoice->status->isCancelled()
                    ? 'Cancelled invoices can’t be emailed.'
                    : 'Draft invoices can’t be emailed. Issue the invoice first.');
            }

            $this->supersedeStuckOrRefuse($invoice);
            // After the in-flight check (its message is more useful); a refusal here also
            // rolls back any takeover above.
            $this->ensureWithinLimits($business, $invoice);

            $recipient = $this->recipientOptions($business, $invoice)[$source] ?? null;
            if ($recipient === null) {
                throw new InvoiceEmailException('There is no email address to send this invoice to.');
            }

            $email = $invoice->emails()->make();
            $email->forceFill([
                'requested_by' => $requester?->getKey(),
                'recipient_email' => $recipient,
                'recipient_source' => $source,
                'subject' => InvoiceMail::subjectFor($invoice),
                'status' => InvoiceEmailStatus::Queued,
                'queued_at' => now(),
            ])->save();

            // SendInvoiceEmail is ShouldQueueAfterCommit: it is only queued if this commits.
            SendInvoiceEmail::dispatch($email->getKey());

            return $email;
        }, self::TRANSACTION_ATTEMPTS);

        $this->recordLimits($business, $invoice);

        return $email;
    }

    /**
     * Claim a queued row for sending. Returns the row (with its invoice and
     * business loaded) only if this call moved it from queued to sending;
     * otherwise null, and nothing may be sent.
     */
    public function claim(int $emailId): ?InvoiceEmail
    {
        return DB::transaction(function () use ($emailId) {
            $email = InvoiceEmail::query()->whereKey($emailId)->lockForUpdate()->first();

            if ($email === null) {
                return null;
            }

            if ($email->status === InvoiceEmailStatus::Sending) {
                // A previous attempt stopped mid-send without recording the outcome (for
                // example the worker was killed). The email may already have gone out, so
                // never send it again automatically.
                $this->finish($email, InvoiceEmailStatus::Failed, 'The previous attempt was interrupted, so the email may or may not have been delivered. Check with the customer before sending it again.');

                return null;
            }

            if ($email->status !== InvoiceEmailStatus::Queued) {
                return null;
            }

            // Locked so a cancellation either commits before this check (and the send
            // is skipped) or waits until the send has been claimed.
            $invoice = Invoice::query()->whereKey($email->invoice_id)->lockForUpdate()->firstOrFail();

            if (! $this->isSendable($invoice)) {
                $this->finish($email, InvoiceEmailStatus::Skipped, 'The invoice was '.strtolower($invoice->status->label()).' before the email was sent.');

                return null;
            }

            $invoice->load('business');

            $claimed = InvoiceEmail::query()
                ->whereKey($email->getKey())
                ->where('status', InvoiceEmailStatus::Queued)
                ->update([
                    'status' => InvoiceEmailStatus::Sending,
                    'attempts' => $email->attempts + 1,
                    'status_at_send' => $invoice->status,
                    'subject' => InvoiceMail::subjectFor($invoice),
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                return null;
            }

            return $email->refresh()->setRelation('invoice', $invoice);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Record a successful send.
     */
    public function markSent(InvoiceEmail $email, ?string $messageId): void
    {
        InvoiceEmail::query()
            ->whereKey($email->getKey())
            ->where('status', InvoiceEmailStatus::Sending)
            ->update([
                'status' => InvoiceEmailStatus::Sent,
                'sent_at' => now(),
                'message_id' => $messageId === null ? null : mb_substr($messageId, 0, 255),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Put a row whose attempt failed temporarily back in the queue for the next attempt.
     */
    public function markForRetry(InvoiceEmail $email, string $error): void
    {
        InvoiceEmail::query()
            ->whereKey($email->getKey())
            ->where('status', InvoiceEmailStatus::Sending)
            ->update([
                'status' => InvoiceEmailStatus::Queued,
                'last_error' => $error,
                'updated_at' => now(),
            ]);
    }

    /**
     * Record that the send failed for good. Does nothing if the row already finished.
     */
    public function markFailed(int $emailId, string $error): void
    {
        InvoiceEmail::query()
            ->whereKey($emailId)
            ->whereIn('status', [InvoiceEmailStatus::Queued, InvoiceEmailStatus::Sending])
            ->update([
                'status' => InvoiceEmailStatus::Failed,
                'failed_at' => now(),
                'last_error' => $error,
                'updated_at' => now(),
            ]);
    }

    public function isSendable(Invoice $invoice): bool
    {
        return $invoice->status->isIssued() || $invoice->status->isPaid();
    }

    /**
     * Whether a queued row has waited long enough to be treated as stuck.
     */
    public static function isStuck(InvoiceEmail $email): bool
    {
        return $email->status === InvoiceEmailStatus::Queued
            && $email->queued_at->lt(now()->subMinutes(self::STUCK_AFTER_MINUTES));
    }

    /**
     * Enforce one send in flight per invoice. A stuck queued row is superseded
     * (moved to skipped) so its job can never send; anything else refuses.
     * Must run inside the transaction that holds the invoice's lock.
     */
    private function supersedeStuckOrRefuse(Invoice $invoice): void
    {
        // Deliberately not FOR UPDATE: for an invoice with no active rows that would
        // lock an index gap shared with other invoices, and concurrent requests for
        // different invoices then deadlock on their inserts. It is not needed: requests
        // for this invoice are already serialized by the invoice row lock taken first
        // (so this read, made after it, sees any earlier request), and the takeover
        // below is a conditional update that fails if a job claimed the row meanwhile.
        $active = $invoice->emails()
            ->whereIn('status', [InvoiceEmailStatus::Queued, InvoiceEmailStatus::Sending])
            ->get();

        if ($active->contains(fn (InvoiceEmail $email) => $email->status === InvoiceEmailStatus::Sending)) {
            throw new InvoiceEmailException('This invoice is being emailed right now. Please check its email history in a minute.');
        }

        if ($active->contains(fn (InvoiceEmail $email) => ! self::isStuck($email))) {
            throw new InvoiceEmailException('This invoice is already queued to be emailed.');
        }

        foreach ($active as $stuck) {
            $superseded = InvoiceEmail::query()
                ->whereKey($stuck->getKey())
                ->where('status', InvoiceEmailStatus::Queued)
                ->update([
                    'status' => InvoiceEmailStatus::Skipped,
                    'failed_at' => now(),
                    'last_error' => 'Superseded by a newer send request.',
                    'updated_at' => now(),
                ]);

            if ($superseded !== 1) {
                // Its job claimed it first: it is being sent, so this request must not continue.
                throw new InvoiceEmailException('This invoice is being emailed right now. Please check its email history in a minute.');
            }
        }
    }

    private function finish(InvoiceEmail $email, InvoiceEmailStatus $status, string $reason): void
    {
        $email->forceFill([
            'status' => $status,
            'failed_at' => now(),
            'last_error' => $reason,
        ])->save();
    }

    private function ensureWithinLimits(Business $business, Invoice $invoice): void
    {
        foreach ($this->limits($business, $invoice) as [$key, $max, $decay, $message]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                throw new InvoiceEmailException(sprintf($message, $minutes === 1 ? '1 minute' : "{$minutes} minutes"));
            }
        }
    }

    private function recordLimits(Business $business, Invoice $invoice): void
    {
        foreach ($this->limits($business, $invoice) as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    private function limits(Business $business, Invoice $invoice): array
    {
        return [
            ["invoice-emails:business:{$business->getKey()}", self::BUSINESS_SENDS_PER_HOUR, 3600,
                'Your business has sent the maximum number of invoice emails for now. Try again in %s.'],
            ["invoice-emails:invoice:{$invoice->getKey()}:day", self::INVOICE_SENDS_PER_DAY, 86400,
                'This invoice has been emailed the maximum number of times today. Try again in %s.'],
            ["invoice-emails:invoice:{$invoice->getKey()}:cooldown", 1, self::INVOICE_COOLDOWN_SECONDS,
                'This invoice was emailed a moment ago. Try again in %s.'],
        ];
    }

    private function ensureBelongsTo(Invoice $invoice, Business $business): void
    {
        if ((int) $invoice->business_id !== (int) $business->getKey()) {
            throw new InvalidArgumentException('The invoice does not belong to this business.');
        }
    }

    private function normalize(?string $email): ?string
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }
}
