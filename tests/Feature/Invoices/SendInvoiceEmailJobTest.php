<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceEmailStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceEmailException;
use App\Jobs\SendInvoiceEmail;
use App\Models\InvoiceEmail;
use App\Models\User;
use App\Services\InvoiceEmailService;
use App\Services\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * The queued job: claiming, idempotency, takeover of stuck sends, races with
 * cancellation, and transient/permanent failures. Jobs are run by hand so each
 * step is controlled.
 */
class SendInvoiceEmailJobTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;
    use SendsInvoiceEmails;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_a_queued_email_is_sent_once_with_the_pdf_and_recorded(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $email = $this->requestSend($user, $invoice);

        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Sent, $email->status);
        $this->assertSame(1, $email->attempts);
        $this->assertSame(InvoiceStatus::Issued, $email->status_at_send);
        $this->assertNotNull($email->sent_at);
        $this->assertNotNull($email->message_id);
        $this->assertNull($email->last_error);

        $this->assertCount(1, $transport->sent);
        $message = $this->sentEmail($transport);
        $this->assertSame('billing@customer.test', $message->getTo()[0]->getAddress());
        $this->assertStringStartsWith('%PDF-', $this->pdfAttachment($message)->getBody());
    }

    public function test_emailing_never_changes_the_invoice(): void
    {
        $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $before = $invoice->fresh()->getAttributes();

        $this->travel(5)->minutes();
        $this->runJob($this->requestSend($user, $invoice));

        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_a_queued_row_cannot_be_sent_twice(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        $this->runJob($email);
        $this->runJob($email);
        $this->runJob($email);

        $this->assertCount(1, $transport->sent);
        $this->assertSame(1, $email->fresh()->attempts);
    }

    public function test_the_job_for_a_sent_row_does_nothing(): void
    {
        $transport = $this->mailTransport();
        $email = InvoiceEmail::factory()->status(InvoiceEmailStatus::Sent)->create(['sent_at' => now()]);

        $this->runJob($email);

        $this->assertSame(0, $transport->calls);
        $this->assertSame(InvoiceEmailStatus::Sent, $email->fresh()->status);
    }

    public function test_the_job_for_a_skipped_or_failed_row_does_nothing(): void
    {
        $transport = $this->mailTransport();

        foreach ([InvoiceEmailStatus::Skipped, InvoiceEmailStatus::Failed] as $status) {
            $email = InvoiceEmail::factory()->status($status)->create(['failed_at' => now(), 'last_error' => 'Earlier']);

            $this->runJob($email);

            $this->assertSame($status, $email->fresh()->status);
            $this->assertSame('Earlier', $email->fresh()->last_error);
        }
        $this->assertSame(0, $transport->calls);
    }

    public function test_the_job_for_a_missing_row_does_nothing(): void
    {
        $transport = $this->mailTransport();

        $this->runJob(999999);

        $this->assertSame(0, $transport->calls);
    }

    public function test_a_failed_send_can_be_sent_again(): void
    {
        $transport = $this->mailTransport([new UnexpectedResponseException('got code "550"', 550)]);
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $first = $this->requestSend($user, $invoice);
        $this->runJob($first);
        $this->assertSame(InvoiceEmailStatus::Failed, $first->fresh()->status);

        $this->travel(2)->minutes();
        $second = $this->requestSend($user, $invoice);
        $this->runJob($second);

        $this->assertSame(InvoiceEmailStatus::Failed, $first->fresh()->status);
        $this->assertSame(InvoiceEmailStatus::Sent, $second->fresh()->status);
        $this->assertCount(1, $transport->sent);
    }

    public function test_a_stuck_queued_send_is_superseded_and_its_old_job_never_sends(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        // The first job is delayed (the worker is down).
        $old = $this->requestSend($user, $invoice);
        Queue::assertPushed(SendInvoiceEmail::class, fn ($job) => $job->invoiceEmailId === $old->id);

        $this->travel(InvoiceEmailService::STUCK_AFTER_MINUTES + 1)->minutes();
        $new = $this->requestSend($user, $invoice);

        $old->refresh();
        $this->assertSame(InvoiceEmailStatus::Skipped, $old->status);
        $this->assertSame('Superseded by a newer send request.', $old->last_error);
        $this->assertSame(InvoiceEmailStatus::Queued, $new->fresh()->status);

        // The delayed old job finally runs, then the new one.
        $this->runJob($old);
        $this->runJob($new);

        $this->assertCount(1, $transport->sent);
        $this->assertSame(InvoiceEmailStatus::Skipped, $old->fresh()->status);
        $this->assertSame(0, $old->fresh()->attempts);
        $this->assertSame(InvoiceEmailStatus::Sent, $new->fresh()->status);
        $this->assertSame(1, $invoice->emails()->where('status', InvoiceEmailStatus::Sent)->count());
    }

    public function test_a_queued_send_that_is_not_stuck_blocks_a_new_request(): void
    {
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $this->requestSend($user, $invoice);

        $this->travel(InvoiceEmailService::STUCK_AFTER_MINUTES - 1)->minutes();

        $this->assertThrows(fn () => $this->requestSend($user, $invoice), InvoiceEmailException::class, 'already queued');
        $this->assertSame(1, $invoice->emails()->count());
    }

    public function test_a_sending_row_is_never_taken_over_however_old(): void
    {
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $email = $this->requestSend($user, $invoice);
        $email->forceFill(['status' => InvoiceEmailStatus::Sending])->save();

        $this->travel(2)->hours();

        $this->assertThrows(fn () => $this->requestSend($user, $invoice), InvoiceEmailException::class, 'being emailed right now');
        $this->assertSame(InvoiceEmailStatus::Sending, $email->fresh()->status);
        $this->assertSame(1, $invoice->emails()->count());
    }

    public function test_a_job_that_claimed_first_wins_over_a_takeover(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $old = $this->requestSend($user, $invoice);
        $this->travel(InvoiceEmailService::STUCK_AFTER_MINUTES + 1)->minutes();

        // The old job claims its row just before the new request arrives.
        $claimed = app(InvoiceEmailService::class)->claim($old->id);
        $this->assertNotNull($claimed);

        $this->assertThrows(fn () => $this->requestSend($user, $invoice), InvoiceEmailException::class, 'being emailed right now');
        $this->assertSame(1, $invoice->emails()->count());
        $this->assertSame(0, $transport->calls);
    }

    public function test_cancelling_before_the_job_claims_the_row_skips_it(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $email = $this->requestSend($user, $invoice);

        app(InvoiceService::class)->cancel($invoice);
        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Skipped, $email->status);
        $this->assertSame('The invoice was cancelled before the email was sent.', $email->last_error);
        $this->assertSame(0, $email->attempts);
        $this->assertSame(0, $transport->calls);
    }

    public function test_cancelling_after_sending_began_still_records_the_email_as_sent(): void
    {
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        // The invoice is cancelled while the SMTP call is under way.
        $transport = $this->mailTransport([fn () => app(InvoiceService::class)->cancel($invoice)]);
        $email = $this->requestSend($user, $invoice);

        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(InvoiceEmailStatus::Sent, $email->status, 'The email really went out, so it must not look unsent.');
        $this->assertSame(InvoiceStatus::Issued, $email->status_at_send);
        $this->assertCount(1, $transport->sent);
    }

    public function test_a_paid_invoice_is_sent_and_a_draft_row_is_skipped(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $paid = app(InvoiceService::class)->markPaid($this->emailableInvoice($user), '2026-09-20');
        $email = $this->requestSend($user, $paid);

        $this->runJob($email);

        $this->assertSame(InvoiceStatus::Paid, $email->fresh()->status_at_send);
        $this->assertStringEndsWith('(paid)', $this->sentEmail($transport)->getSubject());

        $draftRow = InvoiceEmail::factory()->create(['invoice_id' => $this->draftFor($user)->id]);
        $this->runJob($draftRow);
        $this->assertSame(InvoiceEmailStatus::Skipped, $draftRow->fresh()->status);
        $this->assertCount(1, $transport->sent);
    }

    public function test_the_subject_follows_the_status_at_send_time(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $email = $this->requestSend($user, $invoice);
        $this->assertStringEndsNotWith('(paid)', $email->subject);

        app(InvoiceService::class)->markPaid($invoice, '2026-09-25');
        $this->runJob($email);

        $this->assertStringEndsWith('(paid)', $email->fresh()->subject);
        $this->assertSame($email->fresh()->subject, $this->sentEmail($transport)->getSubject());
    }

    public function test_a_temporary_failure_is_retried_and_then_sent(): void
    {
        $transport = $this->mailTransport([new TransportException('Connection could not be established with host "smtp.test:587"')]);
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        $this->assertThrows(fn () => $this->runJob($email), TransportException::class);
        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Queued, $email->status);
        $this->assertSame(1, $email->attempts);
        $this->assertStringContainsString('Connection could not be established', $email->last_error);

        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Sent, $email->status);
        $this->assertSame(2, $email->attempts);
        $this->assertNull($email->last_error);
        $this->assertCount(1, $transport->sent);
    }

    public function test_an_uncertain_5xx_is_retried_not_failed(): void
    {
        $this->mailTransport([new UnexpectedResponseException('got code "554", with message "554 Transaction failed"', 554)]);
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        $this->assertThrows(fn () => $this->runJob($email), UnexpectedResponseException::class);

        $this->assertSame(InvoiceEmailStatus::Queued, $email->fresh()->status);
    }

    public function test_a_permanent_rejection_fails_at_once_without_retrying(): void
    {
        $transport = $this->mailTransport([new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown".', 550)]);
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        $this->runJob($email);
        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Failed, $email->status);
        $this->assertNotNull($email->failed_at);
        $this->assertStringContainsString('550 5.1.1 User unknown', $email->last_error);
        $this->assertSame(1, $transport->calls);
    }

    public function test_when_all_attempts_are_used_up_the_row_is_failed(): void
    {
        $transport = $this->mailTransport([
            new TransportException('Timeout 1'), new TransportException('Timeout 2'), new TransportException('Timeout 3'),
        ]);
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        foreach (range(1, 3) as $attempt) {
            try {
                $this->runJob($email);
            } catch (TransportException $e) {
                // The queue would retry, until the third attempt.
            }
        }
        (new SendInvoiceEmail($email->id))->failed($e);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Failed, $email->status);
        $this->assertSame(3, $email->attempts);
        $this->assertSame('Timeout 3', $email->last_error);
        $this->assertSame(0, count($transport->sent));
    }

    public function test_failed_never_changes_a_row_that_already_finished(): void
    {
        $email = InvoiceEmail::factory()->status(InvoiceEmailStatus::Sent)->create(['sent_at' => now()]);

        (new SendInvoiceEmail($email->id))->failed(new RuntimeException('Late failure'));

        $this->assertSame(InvoiceEmailStatus::Sent, $email->fresh()->status);
        $this->assertNull($email->fresh()->last_error);
    }

    public function test_an_interrupted_send_is_failed_and_never_sent_again_automatically(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));
        // A worker claimed the row and died mid-send, so its outcome was never recorded.
        $email->forceFill(['status' => InvoiceEmailStatus::Sending, 'attempts' => 1])->save();

        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Failed, $email->status);
        $this->assertStringContainsString('may or may not have been delivered', $email->last_error);
        $this->assertSame(0, $transport->calls);
    }

    public function test_the_job_is_queued_after_commit_with_only_the_row_id(): void
    {
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        Queue::assertPushed(SendInvoiceEmail::class, function (SendInvoiceEmail $job) use ($email) {
            return $job->invoiceEmailId === $email->id
                && $job->tries === 3
                && $job->backoff === [60, 300]
                && $job->timeout === 60;
        });
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, new SendInvoiceEmail($email->id));
        // The payload carries the row ID only: no models, addresses or invoice data.
        $payload = serialize(new SendInvoiceEmail($email->id));
        $this->assertStringNotContainsString('App\\Models', $payload);
        $this->assertStringNotContainsString($email->recipient_email, $payload);
    }
}
