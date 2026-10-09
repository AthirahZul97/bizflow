<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementService;
use App\Enums\InvoiceEmailStatus;
use App\Exceptions\EntitlementException;
use App\Jobs\SendInvoiceEmail;
use App\Models\InvoiceEmail;
use App\Models\User;
use App\Services\InvoiceEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Invoices\CreatesInvoices;
use Tests\Feature\Invoices\SendsInvoiceEmails;
use Tests\TestCase;

class InvoiceEmailEntitlementTest extends TestCase
{
    use CreatesInvoices;
    use ManagesSubscriptions;
    use RefreshDatabase;
    use SendsInvoiceEmails;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 10:00:00');
        $this->owner = User::factory()->create();
    }

    public function test_queueing_is_refused_when_the_plan_does_not_include_email(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $this->limitTo($this->owner, ['invoices.email' => false]);

        $this->assertThrows(fn () => $this->requestSend($this->owner, $invoice), EntitlementException::class, "doesn't include invoice emails");

        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    public function test_queueing_is_refused_when_the_plan_never_mentions_email(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $plan = $this->planWith(['customers.max' => 5]);
        $this->subscribe($this->owner, fn ($f) => $f->state(['plan_id' => $plan->getKey()]));

        $this->assertThrows(fn () => $this->requestSend($this->owner, $invoice), EntitlementException::class);
        $this->assertSame(0, InvoiceEmail::count());
    }

    public function test_queueing_is_refused_in_read_only(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $this->makeReadOnly($this->owner);

        $this->assertThrows(fn () => $this->requestSend($this->owner, $invoice), EntitlementException::class, 'read-only');
        Queue::assertNothingPushed();
    }

    public function test_queueing_works_on_a_plan_that_includes_email_and_in_grace(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $this->lapsedPaid($this->owner, 2);

        $email = $this->requestSend($this->owner, $invoice);

        $this->assertSame(InvoiceEmailStatus::Queued, $email->status);
        Queue::assertPushed(SendInvoiceEmail::class, 1);
    }

    public function test_the_entitlement_is_read_fresh_not_from_the_memo(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $this->assertTrue(app(EntitlementService::class)->for($this->businessOf($this->owner))->canWrite());

        $this->makeReadOnly($this->owner);

        $this->assertThrows(fn () => $this->requestSend($this->owner, $invoice), EntitlementException::class);
    }

    public function test_a_queued_email_is_failed_for_good_when_the_plan_loses_email_before_it_is_sent(): void
    {
        Queue::fake();
        $transport = $this->mailTransport();
        $invoice = $this->emailableInvoice($this->owner);
        $email = $this->requestSend($this->owner, $invoice);
        $this->limitTo($this->owner, ['invoices.email' => false]);

        $this->runJob($email);

        $email->refresh();
        $this->assertSame(InvoiceEmailStatus::Failed, $email->status);
        $this->assertStringContainsString("doesn't include invoice emails", $email->last_error);
        $this->assertNotNull($email->failed_at);
        $this->assertSame(0, $email->attempts, 'it was never claimed for sending');
        $this->assertCount(0, $transport->sent);
    }

    public function test_a_queued_email_is_failed_when_the_business_becomes_read_only_before_it_is_sent(): void
    {
        Queue::fake();
        $transport = $this->mailTransport();
        $email = $this->requestSend($this->owner, $this->emailableInvoice($this->owner));
        $this->makeReadOnly($this->owner);

        $this->runJob($email);

        $this->assertSame(InvoiceEmailStatus::Failed, $email->fresh()->status);
        $this->assertStringContainsString('read-only', $email->fresh()->last_error);
        $this->assertCount(0, $transport->sent);
    }

    public function test_a_failed_for_entitlement_row_is_not_retried_and_stays_failed(): void
    {
        Queue::fake();
        $transport = $this->mailTransport();
        $email = $this->requestSend($this->owner, $this->emailableInvoice($this->owner));
        $this->limitTo($this->owner, ['invoices.email' => false]);

        $this->runJob($email);
        // A duplicate or retried job finds nothing to send, even if the plan is restored.
        $this->limitTo($this->owner, ['invoices.email' => true]);
        $this->runJob($email);

        $this->assertSame(InvoiceEmailStatus::Failed, $email->fresh()->status);
        $this->assertCount(0, $transport->sent);
        $this->assertSame(0, $email->fresh()->attempts);
    }

    public function test_the_job_does_not_throw_so_the_queue_does_not_retry_it(): void
    {
        Queue::fake();
        $this->mailTransport();
        $email = $this->requestSend($this->owner, $this->emailableInvoice($this->owner));
        $this->limitTo($this->owner, ['invoices.email' => false]);

        (new SendInvoiceEmail($email->getKey()))->handle(app(InvoiceEmailService::class));

        $this->addToAssertionCount(1);
    }

    public function test_an_entitled_email_is_still_sent_normally(): void
    {
        Queue::fake();
        $transport = $this->mailTransport();
        $email = $this->requestSend($this->owner, $this->emailableInvoice($this->owner));

        $this->runJob($email);

        $this->assertSame(InvoiceEmailStatus::Sent, $email->fresh()->status);
        $this->assertCount(1, $transport->sent);
    }

    public function test_another_businesss_entitlement_does_not_affect_this_one(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $this->limitTo($other, ['invoices.email' => false]);

        $email = $this->requestSend($this->owner, $this->emailableInvoice($this->owner));

        $this->assertSame(InvoiceEmailStatus::Queued, $email->status);
    }

    public function test_the_email_form_is_refused_with_the_reason_when_not_entitled(): void
    {
        $invoice = $this->emailableInvoice($this->owner);
        $this->limitTo($this->owner, ['invoices.email' => false]);

        $this->actingAs($this->owner)->get(route('invoices.email.create', $invoice))
            ->assertForbidden()
            ->assertSee("doesn't include invoice emails");
    }

    public function test_posting_an_email_when_not_entitled_is_refused_and_sends_nothing(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $this->limitTo($this->owner, ['invoices.email' => false]);

        $this->actingAs($this->owner)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertForbidden();

        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    public function test_existing_email_history_stays_visible_after_the_plan_loses_email(): void
    {
        Queue::fake();
        $invoice = $this->emailableInvoice($this->owner);
        $this->requestSend($this->owner, $invoice);
        $this->limitTo($this->owner, ['invoices.email' => false]);

        $this->actingAs($this->owner)->get(route('invoices.show', $invoice))->assertOk()->assertSee('billing@customer.test');
    }
}
