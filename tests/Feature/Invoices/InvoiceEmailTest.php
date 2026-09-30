<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceEmailStatus;
use App\Exceptions\InvoiceEmailException;
use App\Jobs\SendInvoiceEmail;
use App\Models\InvoiceEmail;
use App\Models\User;
use App\Services\InvoiceEmailService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Emailing an invoice through the pages: who may, to which address, what is
 * recorded and shown, and the limits.
 */
class InvoiceEmailTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;
    use SendsInvoiceEmails;

    public function test_the_email_button_shows_only_for_issued_and_paid_invoices(): void
    {
        $user = User::factory()->create();
        $service = app(InvoiceService::class);
        $issued = $this->emailableInvoice($user);
        $paid = $service->markPaid($this->emailableInvoice($user), '2026-09-20');
        $cancelled = $service->cancel($this->emailableInvoice($user));
        $draft = $this->draftFor($user);

        foreach ([$issued, $paid] as $invoice) {
            $this->actingAs($user)->get(route('invoices.show', $invoice))->assertSee(route('invoices.email.create', $invoice));
        }
        foreach ([$cancelled, $draft] as $invoice) {
            $this->actingAs($user)->get(route('invoices.show', $invoice))->assertDontSee('Email invoice');
        }
    }

    public function test_drafts_and_cancelled_invoices_cannot_be_emailed(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $draft = $this->draftFor($user, $this->customerFor($user, ['email' => 'a@customer.test']));
        $cancelled = app(InvoiceService::class)->cancel($this->emailableInvoice($user));

        $this->actingAs($user)->get(route('invoices.email.create', $draft))
            ->assertForbidden()->assertSee('Draft invoices can’t be emailed. Issue the invoice first.');
        $this->actingAs($user)->post(route('invoices.email.store', $draft), ['recipient' => 'invoice'])->assertForbidden();
        $this->actingAs($user)->get(route('invoices.email.create', $cancelled))
            ->assertForbidden()->assertSee('Cancelled invoices can’t be emailed.');
        $this->actingAs($user)->post(route('invoices.email.store', $cancelled), ['recipient' => 'invoice'])->assertForbidden();

        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    public function test_the_service_refuses_drafts_and_cancelled_invoices_too(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor($user, $this->customerFor($user, ['email' => 'a@customer.test']));
        $cancelled = app(InvoiceService::class)->cancel($this->emailableInvoice($user));

        $this->assertThrows(fn () => $this->requestSend($user, $draft), InvoiceEmailException::class, 'Draft invoices');
        $this->assertThrows(fn () => $this->requestSend($user, $cancelled), InvoiceEmailException::class, 'Cancelled invoices');
        $this->assertSame(0, InvoiceEmail::count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $invoice = $this->emailableInvoice(User::factory()->create());

        $this->get(route('invoices.email.create', $invoice))->assertRedirect(route('login'));
        $this->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertRedirect(route('login'));
    }

    public function test_the_form_offers_the_invoice_email_and_shows_what_will_be_sent(): void
    {
        $user = User::factory()->create(['name' => 'Acme Studio']);
        $invoice = $this->emailableInvoice($user);

        $this->actingAs($user)->get(route('invoices.email.create', $invoice))
            ->assertOk()
            ->assertSee('billing@customer.test')
            ->assertSee('The email on this invoice')
            ->assertDontSee('value="customer"', false)
            ->assertSee('Invoice INV-00001 from Acme Studio')
            ->assertSee('INV-00001.pdf')
            ->assertSee('Your business profile has no email address');
    }

    public function test_a_changed_customer_email_is_offered_while_the_invoice_keeps_its_copy(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user, 'old@customer.test');
        $invoice->customer->update(['email' => 'new@customer.test']);

        $this->actingAs($user)->get(route('invoices.email.create', $invoice))
            ->assertSee('old@customer.test')
            ->assertSee('new@customer.test')
            ->assertSee('changed since this invoice was issued');

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'customer'])
            ->assertRedirect(route('invoices.show', $invoice));

        $email = InvoiceEmail::sole();
        $this->assertSame('new@customer.test', $email->recipient_email);
        $this->assertSame(InvoiceEmail::SOURCE_CUSTOMER, $email->recipient_source);
        // The invoice's own copy of the customer's details is untouched.
        $this->assertSame('old@customer.test', $invoice->fresh()->customer_email);
    }

    public function test_an_invoice_without_any_address_cannot_be_sent(): void
    {
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user, '');

        $this->actingAs($user)->get(route('invoices.email.create', $invoice))
            ->assertOk()->assertSee('Neither this invoice nor the customer has an email address');
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertSessionHasErrors('recipient');

        $this->assertSame(0, InvoiceEmail::count());
    }

    public function test_sending_queues_the_job_and_records_who_asked(): void
    {
        Queue::fake();
        $user = User::factory()->create(['name' => 'Acme Studio']);
        $invoice = $this->emailableInvoice($user);

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status', 'Invoice INV-00001 is queued to be emailed to billing@customer.test.');

        $email = InvoiceEmail::sole();
        $this->assertSame($invoice->id, $email->invoice_id);
        $this->assertSame($user->id, $email->requested_by);
        $this->assertSame('billing@customer.test', $email->recipient_email);
        $this->assertSame(InvoiceEmail::SOURCE_INVOICE, $email->recipient_source);
        $this->assertSame('Invoice INV-00001 from Acme Studio', $email->subject);
        $this->assertSame(InvoiceEmailStatus::Queued, $email->status);
        Queue::assertPushed(SendInvoiceEmail::class, fn ($job) => $job->invoiceEmailId === $email->id);
    }

    public function test_the_recipient_choice_is_validated_and_free_text_is_ignored(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), [])->assertSessionHasErrors('recipient');
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'attacker@evil.test'])->assertSessionHasErrors('recipient');
        // The customer's address is the same as the invoice's, so it is not a separate choice.
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'customer'])->assertSessionHasErrors('recipient');
        $this->assertSame(0, InvoiceEmail::count());

        $other = User::factory()->create();
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), [
            'recipient' => 'invoice',
            'recipient_email' => 'attacker@evil.test',
            'to' => 'attacker@evil.test',
            'subject' => 'Forged',
            'message' => 'Click here',
            'invoice_id' => $this->emailableInvoice($other)->id,
            'business_id' => $this->businessOf($other)->id,
            'requested_by' => $other->id,
            'status' => 'sent',
        ])->assertSessionHasNoErrors();

        $email = InvoiceEmail::sole();
        $this->assertSame('billing@customer.test', $email->recipient_email);
        $this->assertSame($invoice->id, $email->invoice_id);
        $this->assertSame($user->id, $email->requested_by);
        $this->assertSame(InvoiceEmailStatus::Queued, $email->status);
        $this->assertStringNotContainsString('Forged', $email->subject);
    }

    public function test_only_one_send_can_be_in_flight(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice']);
        $this->actingAs($user)->from(route('invoices.email.create', $invoice))
            ->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertRedirect(route('invoices.email.create', $invoice))
            ->assertSessionHas('error', 'This invoice is already queued to be emailed.');

        $this->assertSame(1, InvoiceEmail::count());
        Queue::assertPushed(SendInvoiceEmail::class, 1);
    }

    public function test_the_whole_flow_sends_the_email_and_shows_it_in_the_history_and_list(): void
    {
        // The test queue runs jobs synchronously once the request's transaction commits.
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $before = $invoice->fresh()->getAttributes();

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice']);

        $this->assertCount(1, $transport->sent);
        $this->assertSame(InvoiceEmailStatus::Sent, InvoiceEmail::sole()->status);
        $this->assertSame($before, $invoice->fresh()->getAttributes());

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('Email history')
            ->assertSee('Sent')
            ->assertSee('billing@customer.test')
            ->assertSee('email on the invoice');
        $this->actingAs($user)->get(route('invoices.index'))
            ->assertSee('Emailed '.now()->format('d M Y'));
    }

    public function test_the_history_explains_failures_and_skips(): void
    {
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        InvoiceEmail::factory()->status(InvoiceEmailStatus::Failed)->create([
            'invoice_id' => $invoice->id, 'failed_at' => now(), 'last_error' => 'Expected response code "250" but got code "550".',
        ]);
        InvoiceEmail::factory()->status(InvoiceEmailStatus::Skipped)->create([
            'invoice_id' => $invoice->id, 'failed_at' => now(), 'last_error' => 'Superseded by a newer send request.',
        ]);

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('Failed')
            ->assertSee('got code &quot;550&quot;', false)
            ->assertSee('Not sent')
            ->assertSee('Superseded by a newer send request.')
            ->assertDontSee('Emailed');
        $this->actingAs($user)->get(route('invoices.index'))->assertDontSee('Emailed');
    }

    public function test_a_stuck_send_is_flagged_and_can_be_replaced(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice']);

        $this->travel(InvoiceEmailService::STUCK_AFTER_MINUTES + 1)->minutes();

        $this->actingAs($user)->get(route('invoices.show', $invoice))->assertSee('Still waiting for the queue worker');
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertSessionHasNoErrors();

        $this->assertSame(['queued', 'skipped'], $invoice->emails()->orderByDesc('id')->pluck('status')->map->value->all());
    }

    public function test_another_businesss_invoice_is_not_found(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $invoice = $this->emailableInvoice($owner);

        $this->actingAs($intruder)->get(route('invoices.email.create', $invoice))->assertNotFound();
        $this->actingAs($intruder)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertNotFound();

        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    public function test_the_service_refuses_another_businesss_invoice_and_customer(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $invoice = $this->emailableInvoice($owner);
        $service = app(InvoiceEmailService::class);

        $this->assertThrows(fn () => $service->queue($this->businessOf($intruder), $invoice, $intruder, 'invoice'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $service->recipientOptions($this->businessOf($intruder), $invoice), InvalidArgumentException::class);
        $this->assertSame(0, InvoiceEmail::count());
    }

    public function test_the_history_on_one_invoice_never_shows_another_invoices_sends(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->emailableInvoice($user);
        InvoiceEmail::factory()->create(['invoice_id' => $this->emailableInvoice($other, 'secret@theirs.test')->id]);

        $this->actingAs($user)->get(route('invoices.show', $mine))
            ->assertDontSee('secret@theirs.test')
            ->assertDontSee('Email history');
    }

    public function test_the_job_is_only_queued_when_the_transaction_commits(): void
    {
        // The real database queue (not Queue::fake, which ignores after-commit).
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        $email = DB::transaction(function () use ($user, $invoice) {
            $email = $this->requestSend($user, $invoice);
            $this->assertSame(0, DB::table('jobs')->count(), 'Nothing is queued before the commit.');

            return $email;
        });

        $job = DB::table('jobs')->sole();
        $payload = json_decode($job->payload, true);
        $this->assertSame(SendInvoiceEmail::class, $payload['displayName']);
        $this->assertSame($email->id, unserialize($payload['data']['command'])->invoiceEmailId);
        $this->assertSame(InvoiceEmailStatus::Queued, $email->fresh()->status);
    }

    public function test_a_rolled_back_request_leaves_no_job_and_no_history(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        DB::beginTransaction();
        $this->requestSend($user, $invoice);
        DB::rollBack();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, InvoiceEmail::count());
    }

    public function test_refused_requests_are_shown_but_not_logged_as_errors(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $this->requestSend($user, $invoice);
        Log::spy();

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertSessionHas('error', 'This invoice is already queued to be emailed.');

        Log::shouldNotHaveReceived('error');
    }

    public function test_an_invoice_can_only_be_emailed_once_a_minute(): void
    {
        $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice']);
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertSessionHas('error', 'This invoice was emailed a moment ago. Try again in 1 minute.');

        $this->travel(61)->seconds();
        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertSessionHasNoErrors();
        $this->assertSame(2, InvoiceEmail::where('status', InvoiceEmailStatus::Sent)->count());
    }

    public function test_an_invoice_can_be_emailed_at_most_five_times_a_day(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        foreach (range(1, InvoiceEmailService::INVOICE_SENDS_PER_DAY) as $i) {
            RateLimiter::hit("invoice-emails:invoice:{$invoice->id}:day", 86400);
        }

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertSessionHas('error', fn ($message) => str_starts_with($message, 'This invoice has been emailed the maximum number of times today.'));

        $this->assertSame(0, InvoiceEmail::count());
    }

    public function test_a_business_can_send_at_most_thirty_invoice_emails_an_hour(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $business = $this->businessOf($user);
        foreach (range(1, InvoiceEmailService::BUSINESS_SENDS_PER_HOUR) as $i) {
            RateLimiter::hit("invoice-emails:business:{$business->id}", 3600);
        }

        $this->actingAs($user)->post(route('invoices.email.store', $this->emailableInvoice($user)), ['recipient' => 'invoice'])
            ->assertSessionHas('error', fn ($message) => str_starts_with($message, 'Your business has sent the maximum number of invoice emails for now.'));
        $this->assertSame(0, InvoiceEmail::count());

        // Another business is not affected.
        $this->actingAs($other)->post(route('invoices.email.store', $this->emailableInvoice($other)), ['recipient' => 'invoice'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, InvoiceEmail::count());
    }

    public function test_send_requests_are_throttled_per_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertStatus(302);
        }

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])->assertStatus(429);
    }
}
