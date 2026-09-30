<?php

namespace App\Jobs;

use App\Mail\InvoiceMail;
use App\Services\InvoiceEmailService;
use App\Support\MailFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one invoice email from its send-history row.
 *
 * The payload is only the row ID. Everything else (invoice, business, recipient)
 * is read fresh when the job runs, and the invoice is re-checked: a row is only
 * sent if InvoiceEmailService::claim() moves it from queued to sending. Running
 * the job again for a row that was sent, skipped, failed or superseded does nothing.
 *
 * The business always comes from the invoice; this job never uses CurrentBusiness.
 */
class SendInvoiceEmail implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * Below the queue's retry_after (90s), so an attempt is never picked up twice.
     */
    public int $timeout = 60;

    public function __construct(public readonly int $invoiceEmailId) {}

    public function handle(InvoiceEmailService $emails): void
    {
        $email = $emails->claim($this->invoiceEmailId);

        if ($email === null) {
            return;
        }

        try {
            $sent = Mail::to($email->recipient_email)->send(new InvoiceMail($email->invoice));
        } catch (Throwable $e) {
            if (MailFailure::isPermanent($e)) {
                $emails->markFailed($email->getKey(), MailFailure::message($e));
                $this->fail($e);

                return;
            }

            // Temporary (or not certainly permanent): back to queued for the next attempt.
            $emails->markForRetry($email, MailFailure::message($e));

            throw $e;
        }

        $emails->markSent($email, $sent?->getMessageId());
    }

    /**
     * All attempts are used up (or the failure was permanent).
     */
    public function failed(?Throwable $e): void
    {
        app(InvoiceEmailService::class)->markFailed(
            $this->invoiceEmailId,
            $e === null ? 'The email could not be sent.' : MailFailure::message($e),
        );
    }
}
