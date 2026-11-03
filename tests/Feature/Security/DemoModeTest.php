<?php

namespace Tests\Feature\Security;

use App\Enums\InvoiceEmailStatus;
use App\Exceptions\InvoiceEmailException;
use App\Jobs\SendInvoiceEmail;
use App\Models\Customer;
use App\Models\InvoiceEmail;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Ocr\Providers\DisabledReceiptOcrProvider;
use App\Ocr\Providers\FakeReceiptOcrProvider;
use App\Ocr\ReceiptOcrProvider;
use App\Services\InvoiceEmailService;
use App\Support\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\Feature\Invoices\CreatesInvoices;
use Tests\Feature\Invoices\SendsInvoiceEmails;
use Tests\TestCase;

/**
 * Demo mode only takes things away, and it is decided by configuration alone: registration closes,
 * no invoice email is queued, claimed or delivered, the email-based flows are off, and nothing a
 * request carries can change any of that.
 */
class DemoModeTest extends TestCase
{
    use CreatesInvoices;
    use ManagesSubscriptions;
    use RefreshDatabase;
    use SendsInvoiceEmails;

    private function demo(): void
    {
        config(['bizflow.demo' => true]);
    }

    // ---- the contract ---------------------------------------------------------------------------

    public function test_the_defaults_leave_local_development_unchanged(): void
    {
        $this->assertFalse(DemoMode::enabled());
        $this->assertTrue(DemoMode::registrationOpen());
        $this->assertTrue(DemoMode::emailFlowsEnabled());
        $this->assertTrue(DemoMode::invoiceEmailAllowed());
        $this->assertTrue(DemoMode::deliveryAllowed());
        $this->assertFalse(config('bizflow.require_verified_email'));
    }

    public function test_demo_mode_forces_registration_closed_and_email_off(): void
    {
        $this->demo();
        config(['bizflow.registration' => 'open']);

        $this->assertFalse(DemoMode::registrationOpen());
        $this->assertFalse(DemoMode::emailFlowsEnabled());
        $this->assertFalse(DemoMode::invoiceEmailAllowed());
    }

    public function test_registration_can_also_be_closed_without_demo_mode(): void
    {
        config(['bizflow.registration' => 'closed']);

        $this->assertFalse(DemoMode::registrationOpen());
        $this->get(route('register'))->assertNotFound();
    }

    public function test_only_open_and_closed_values_are_understood_and_anything_else_is_closed(): void
    {
        config(['bizflow.registration' => 'maybe']);

        $this->assertFalse(DemoMode::registrationOpen());
    }

    // ---- registration ---------------------------------------------------------------------------

    public function test_registration_is_not_available_in_demo_mode(): void
    {
        $this->demo();
        Event::fake();

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register'), [
            'name' => 'Intruder', 'business_name' => 'Intruder Sdn Bhd', 'email' => 'intruder@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertNotFound();

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_a_forged_request_cannot_reopen_registration(): void
    {
        $this->demo();

        $payload = [
            'name' => 'Intruder', 'business_name' => 'X', 'email' => 'intruder@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            'demo' => '0', 'bizflow_demo_mode' => 'false', 'registration' => 'open',
        ];

        $this->withHeaders(['X-Demo-Mode' => '0', 'X-Forwarded-For' => '10.0.0.1', 'X-Bizflow-Registration' => 'open'])
            ->withCookies(['demo' => '0', 'registration' => 'open'])
            ->post(route('register').'?demo=0&registration=open', $payload)
            ->assertNotFound();

        $this->assertSame(0, User::count());
    }

    public function test_pages_do_not_offer_what_demo_mode_has_taken_away(): void
    {
        $this->demo();

        foreach (['/', route('login')] as $url) {
            $page = $this->get($url)->assertOk();
            $page->assertDontSee(route('register'), false)
                ->assertDontSee('Create an account')
                ->assertDontSee(route('password.request'), false)
                ->assertDontSee('Forgot your password');
        }
    }

    public function test_pages_offer_them_outside_demo_mode(): void
    {
        $this->get('/')->assertSee(route('register'), false);
        $this->get(route('login'))->assertSee(route('register'), false)->assertSee(route('password.request'), false);
    }

    public function test_demo_pages_carry_a_clear_banner_and_other_pages_do_not(): void
    {
        $this->get(route('login'))->assertDontSee('data-testid="demo-banner"', false);

        $this->demo();

        $this->get(route('login'))->assertSee('data-testid="demo-banner"', false)
            ->assertSee('Demo environment')->assertSee('not real OCR');
    }

    // ---- invoice email: before queueing ---------------------------------------------------------

    public function test_no_invoice_email_is_queued_in_demo_mode(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $this->demo();

        $this->assertThrows(fn () => $this->requestSend($user, $invoice), InvoiceEmailException::class, 'turned off in the demo');

        $this->assertSame(0, InvoiceEmail::count(), 'no history row either');
        Queue::assertNothingPushed();
    }

    public function test_the_email_pages_refuse_in_demo_mode_and_offer_the_pdf_instead(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $this->demo();

        $this->actingAs($user)->get(route('invoices.email.create', $invoice))
            ->assertOk()->assertSee('data-email-disabled', false)->assertSee(route('invoices.pdf', $invoice), false)
            ->assertDontSee('Send invoice');

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    public function test_a_forged_request_cannot_turn_invoice_email_back_on(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);
        $this->demo();

        $this->withHeaders(['X-Demo-Mode' => '0'])
            ->actingAs($user)
            ->post(route('invoices.email.store', $invoice).'?demo=0', ['recipient' => 'invoice', 'demo' => '0', 'force' => '1'])
            ->assertSessionHas('error');

        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    // ---- invoice email: when the worker claims it ------------------------------------------------

    public function test_a_row_queued_before_demo_mode_is_never_sent_by_the_worker(): void
    {
        Queue::fake();
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));
        $this->assertSame(InvoiceEmailStatus::Queued, $email->status);

        $this->demo();
        $this->runJob($email);

        $row = $email->fresh();
        $this->assertSame(InvoiceEmailStatus::Skipped, $row->status);
        $this->assertStringContainsString('turned off in the demo', $row->last_error);
        $this->assertNull($row->sent_at);
        $this->assertSame([], $transport->sent, 'nothing reached the transport');

        // A redelivered job does nothing more: the row is final.
        $this->runJob($email);
        $this->assertSame(InvoiceEmailStatus::Skipped, $email->fresh()->status);
        $this->assertSame([], $transport->sent);
    }

    // ---- invoice email: before delivery ---------------------------------------------------------

    public function test_a_message_that_is_vetoed_before_the_transport_is_never_recorded_as_sent(): void
    {
        Queue::fake();
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $email = $this->requestSend($user, $this->emailableInvoice($user));

        // Anything that cancels a message (here a listener, as the delivery guard does) makes send() return null.
        Event::listen(MessageSending::class, fn () => false);

        $this->runJob($email);

        $row = $email->fresh();
        $this->assertSame(InvoiceEmailStatus::Failed, $row->status, 'a cancelled message is not a sent one');
        $this->assertNull($row->sent_at);
        $this->assertNull($row->message_id);
        $this->assertStringContainsString('Not sent', $row->last_error);
        $this->assertSame([], $transport->sent);

        $this->runJob($email);
        $this->assertSame(InvoiceEmailStatus::Failed, $email->fresh()->status, 'and it is not retried into a different outcome');
    }

    public function test_the_delivery_guard_cancels_mail_on_a_real_transport_in_demo_mode(): void
    {
        $transport = $this->mailTransport();
        $this->demo();

        $this->assertFalse(DemoMode::deliveryAllowed());

        $result = Mail::raw('hello', fn ($message) => $message->to('someone@example.test')->subject('Hi'));

        $this->assertNull($result);
        $this->assertSame([], $transport->sent);
    }

    public function test_the_delivery_guard_lets_the_log_and_array_transports_through_in_demo_mode(): void
    {
        $this->demo();

        foreach (['array', 'log'] as $mailer) {
            config(['mail.default' => $mailer]);
            Mail::purge($mailer);

            $this->assertTrue(DemoMode::deliveryAllowed(), "{$mailer} cannot deliver");
        }

        config(['mail.default' => 'array']);
        Mail::purge('array');
        $this->assertNotNull(Mail::raw('hello', fn ($message) => $message->to('someone@example.test')->subject('Hi')));
    }

    public function test_a_failover_or_unknown_transport_is_not_treated_as_safe_in_demo_mode(): void
    {
        $this->demo();

        foreach (['failover', 'smtp', 'ses', 'sendmail', 'does-not-exist'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->assertFalse(DemoMode::deliveryAllowed(), $mailer);
        }
    }

    public function test_the_guard_also_stops_account_emails(): void
    {
        $transport = $this->mailTransport();
        $this->demo();
        $user = User::factory()->create();

        $user->notify(new QueuedResetPassword('token'));
        $user->sendEmailVerificationNotification();

        $this->assertSame([], $transport->sent);
    }

    public function test_outside_demo_mode_mail_is_delivered_as_before(): void
    {
        $transport = $this->mailTransport();

        $this->assertNotNull(Mail::raw('hello', fn ($message) => $message->to('someone@example.test')->subject('Hi')));
        $this->assertCount(1, $transport->sent);
    }

    // ---- the account flows ----------------------------------------------------------------------

    public function test_password_reset_and_verification_do_not_exist_in_demo_mode(): void
    {
        Notification::fake();
        $this->demo();
        $user = User::factory()->unverified()->create();

        $this->get(route('password.request'))->assertNotFound();
        $this->post(route('password.email'), ['email' => $user->email])->assertNotFound();
        $this->get(route('password.reset', 'token'))->assertNotFound();
        $this->post(route('password.store'), ['token' => 't', 'email' => $user->email, 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])->assertNotFound();
        $this->actingAs($user)->get(route('verification.notice'))->assertNotFound();
        $this->actingAs($user)->post(route('verification.send'))->assertNotFound();

        Notification::assertNothingSent();
    }

    // ---- OCR ------------------------------------------------------------------------------------

    public function test_the_demo_ocr_provider_is_refused_in_production_unless_demo_mode_is_on(): void
    {
        $this->app['env'] = 'production';

        $this->assertThrows(fn () => app(ReceiptOcrProvider::class), LogicException::class, 'only allowed in demo mode');

        $this->demo();
        $this->assertInstanceOf(FakeReceiptOcrProvider::class, app(ReceiptOcrProvider::class));
    }

    public function test_ocr_can_be_switched_off_anywhere(): void
    {
        $this->app['env'] = 'production';
        config(['ocr.driver' => 'none']);

        $this->assertInstanceOf(DisabledReceiptOcrProvider::class, app(ReceiptOcrProvider::class));
    }

    public function test_demo_receipts_stay_labelled_as_demo_ocr(): void
    {
        $this->demo();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('expense-receipts.create'))
            ->assertOk()->assertSee('data-testid="fake-ocr-banner"', false)->assertSee('Demo OCR');
    }

    // ---- the demo is still a tenant-isolated, writable application ------------------------------

    public function test_demo_accounts_can_write_within_their_own_business_only(): void
    {
        $this->demo();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->post(route('customers.store'), ['name' => 'Alice Customer'])->assertRedirect();
        $this->actingAs($bob)->post(route('customers.store'), ['name' => 'Bob Customer'])->assertRedirect();

        $aliceCustomer = $this->businessOf($alice)->customers()->sole();
        $this->assertSame('Alice Customer', $aliceCustomer->name);
        $this->assertSame(1, $this->businessOf($bob)->customers()->count());

        $this->actingAs($bob)->get(route('customers.show', $aliceCustomer))->assertNotFound();
        $this->actingAs($bob)->put(route('customers.update', $aliceCustomer), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($bob)->delete(route('customers.destroy', $aliceCustomer))->assertNotFound();

        $this->assertSame('Alice Customer', $aliceCustomer->fresh()->name);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_demo_mode_does_not_loosen_the_subscription_read_only_rule(): void
    {
        $this->demo();
        $user = User::factory()->create();
        $this->makeReadOnly($user);

        $this->actingAs($user)->get(route('customers.index'))->assertOk();
        $this->actingAs($user)->post(route('customers.store'), ['name' => 'Late'])->assertForbidden();
        $this->assertSame(0, $this->businessOf($user)->customers()->count());
    }

    // ---- verification gate (private beta) -------------------------------------------------------

    public function test_unverified_accounts_cannot_email_invoices_when_verification_is_required(): void
    {
        Queue::fake();
        config(['bizflow.require_verified_email' => true]);
        $unverified = User::factory()->unverified()->create();
        $invoice = $this->emailableInvoice($unverified);

        $this->actingAs($unverified)->get(route('invoices.email.create', $invoice))->assertRedirect(route('verification.notice'));
        $this->actingAs($unverified)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertRedirect(route('verification.notice'));

        // The service is authoritative, whoever calls it.
        $this->assertThrows(fn () => $this->requestSend($unverified, $invoice), InvoiceEmailException::class, 'Verify your email');
        $this->assertSame(0, InvoiceEmail::count());
        Queue::assertNothingPushed();
    }

    public function test_verified_accounts_can_email_invoices_when_verification_is_required(): void
    {
        Queue::fake();
        config(['bizflow.require_verified_email' => true]);
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        $this->actingAs($user)->post(route('invoices.email.store', $invoice), ['recipient' => 'invoice'])
            ->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(1, InvoiceEmail::count());
        Queue::assertPushed(SendInvoiceEmail::class);
    }

    public function test_verification_is_not_required_by_default(): void
    {
        Queue::fake();
        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->post(route('invoices.email.store', $this->emailableInvoice($unverified)), ['recipient' => 'invoice'])
            ->assertRedirect();

        $this->assertSame(1, InvoiceEmail::count());
    }

    public function test_a_system_request_without_a_person_is_not_blocked_by_verification(): void
    {
        Queue::fake();
        config(['bizflow.require_verified_email' => true]);
        $owner = User::factory()->unverified()->create();
        $invoice = $this->emailableInvoice($owner);

        $email = app(InvoiceEmailService::class)->queue($this->businessOf($owner), $invoice, null, InvoiceEmail::SOURCE_INVOICE);

        $this->assertNull($email->requested_by);
    }
}
