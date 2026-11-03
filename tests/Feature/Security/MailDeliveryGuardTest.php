<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\Invoices\SendsInvoiceEmails;
use Tests\Support\ScriptedTransport;
use Tests\TestCase;

/**
 * Demo mode cannot deliver real email, whichever mailer the code picks and however the message got
 * there: the guard is on every transport, not just the default one, and it decides when the message
 * is sent, so a job queued before demo mode was switched on is stopped too. Nothing here opens a
 * network connection or starts a mail program (that is the point).
 */
class MailDeliveryGuardTest extends TestCase
{
    use RefreshDatabase;
    use SendsInvoiceEmails;

    private const TOKEN = 'RESET-TOKEN-0123456789abcdef';

    private function demo(): void
    {
        config(['bizflow.demo' => true]);
        Mail::purge();
    }

    /**
     * A second mailer, not the default, that records what reaches it (a stand-in for smtp/ses/...).
     */
    private function namedMailer(string $name = 'smtpish'): ScriptedTransport
    {
        $transport = new ScriptedTransport;
        Mail::extend($name, fn () => $transport);
        config(["mail.mailers.{$name}" => ['transport' => $name]]);
        Mail::purge($name);

        return $transport;
    }

    private function send(string $mailer): mixed
    {
        return Mail::mailer($mailer)->raw('hello', fn ($message) => $message->to('someone@example.test')->subject('Hi'));
    }

    // ---- named mailers --------------------------------------------------------------------------

    public function test_a_named_mailer_cannot_deliver_in_demo_mode_even_when_the_default_is_harmless(): void
    {
        config(['mail.default' => 'array']);
        $transport = $this->namedMailer();
        $this->demo();

        $this->assertNull($this->send('smtpish'), 'a cancelled send returns null, never a sent message');
        $this->assertSame([], $transport->sent);
        $this->assertSame(0, $transport->calls, 'the transport was never called');
    }

    public function test_the_same_named_mailer_delivers_normally_outside_demo_mode(): void
    {
        config(['mail.default' => 'array']);
        $transport = $this->namedMailer();

        $this->assertNotNull($this->send('smtpish'));
        $this->assertCount(1, $transport->sent);
    }

    public function test_the_real_smtp_mailer_is_stopped_before_it_connects(): void
    {
        config([
            'mail.default' => 'log',
            // Nothing listens here: if the guard let it through, this would throw a connection error.
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'username' => null, 'password' => null, 'timeout' => 1],
        ]);
        $this->demo();

        $this->assertNull($this->send('smtp'));
    }

    public function test_the_sendmail_mailer_never_starts_a_process_in_demo_mode(): void
    {
        config([
            'mail.default' => 'array',
            'mail.mailers.sendmail' => ['transport' => 'sendmail', 'path' => '/definitely/not/installed/sendmail -bs'],
        ]);
        $this->demo();

        $this->assertNull($this->send('sendmail'));
    }

    public function test_composite_mailers_cannot_reach_a_real_transport_either(): void
    {
        config(['mail.default' => 'log']);
        $transport = $this->namedMailer();
        config(['mail.mailers.fallbacks' => ['transport' => 'failover', 'mailers' => ['smtpish']]]);
        config(['mail.mailers.rotation' => ['transport' => 'roundrobin', 'mailers' => ['smtpish']]]);
        $this->demo();

        $this->assertNull($this->send('fallbacks'));
        $this->assertNull($this->send('rotation'));
        $this->assertSame(0, $transport->calls);
    }

    public function test_only_the_log_and_array_mailers_work_in_demo_mode(): void
    {
        $this->demo();

        foreach (['log', 'array'] as $mailer) {
            $this->assertNotNull($this->send($mailer), "{$mailer} keeps the message inside the application");
        }
    }

    public function test_switching_demo_mode_on_stops_a_mailer_that_was_already_built(): void
    {
        config(['mail.default' => 'array']);
        $transport = $this->namedMailer();

        $this->assertNotNull($this->send('smtpish'), 'built and used before demo mode');

        config(['bizflow.demo' => true]);

        $this->assertNull($this->send('smtpish'), 'the already-built mailer is guarded when it sends');
        $this->assertCount(1, $transport->sent);
    }

    // ---- queued account emails across a switch into demo mode -----------------------------------

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--queue' => 'default']);
    }

    public function test_a_reset_email_queued_before_demo_mode_is_not_delivered_after_it(): void
    {
        $transport = $this->mailTransport();
        $this->useDatabaseQueue();
        $user = User::factory()->create();

        $user->sendPasswordResetNotification(self::TOKEN);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->demo();
        $this->work();

        $this->assertSame(0, DB::table('jobs')->count(), 'the job ran');
        $this->assertSame(0, $transport->calls);
        $this->assertSame([], $transport->sent);
    }

    public function test_a_verification_email_queued_before_demo_mode_is_not_delivered_after_it(): void
    {
        $transport = $this->mailTransport();
        $this->useDatabaseQueue();
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();
        $this->demo();
        $this->work();

        $this->assertSame(0, $transport->calls);
    }

    public function test_the_same_emails_are_delivered_when_demo_mode_is_off(): void
    {
        $transport = $this->mailTransport();
        $this->useDatabaseQueue();
        $user = User::factory()->unverified()->create();

        $user->sendPasswordResetNotification(self::TOKEN);
        $this->work();
        $user->sendEmailVerificationNotification();
        $this->work();

        $this->assertCount(2, $transport->sent, 'the control: the worker really does send these');
        $this->assertStringContainsString('/reset-password/'.self::TOKEN, $transport->sent[0]->getMessage()->toString());
    }

    // ---- the queued payload ---------------------------------------------------------------------

    public function test_the_reset_token_is_not_readable_in_the_queued_payload(): void
    {
        $this->useDatabaseQueue();
        $user = User::factory()->create();

        $user->notify(new QueuedResetPassword(self::TOKEN));

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString(self::TOKEN, $payload);
        $this->assertStringNotContainsString(urlencode(self::TOKEN), $payload);
        $this->assertStringNotContainsString('ResetPassword', json_decode($payload, true)['data']['command'], 'the serialized notification is encrypted');
        $this->assertStringStartsWith('eyJ', json_decode($payload, true)['data']['command'], 'an encrypted envelope, not serialized PHP');
    }

    public function test_the_verification_link_is_not_readable_in_the_queued_payload(): void
    {
        $this->useDatabaseQueue();
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString('verify-email', $payload);
        $this->assertStringNotContainsString('signature', $payload);
        $this->assertStringStartsWith('eyJ', json_decode($payload, true)['data']['command']);
    }

    public function test_an_encrypted_job_still_runs_and_sends_the_token(): void
    {
        $transport = $this->mailTransport();
        $this->useDatabaseQueue();

        User::factory()->create()->sendPasswordResetNotification(self::TOKEN);
        $this->work();

        $this->assertCount(1, $transport->sent);
        $this->assertStringContainsString(self::TOKEN, $transport->sent[0]->getMessage()->toString());
    }

    public function test_a_failed_reset_email_leaves_no_readable_token_in_the_failed_jobs_table(): void
    {
        $this->mailTransport([new RuntimeException('mail server unreachable')]);
        $this->useDatabaseQueue();

        User::factory()->create()->sendPasswordResetNotification(self::TOKEN);
        $this->work();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $failed = DB::table('failed_jobs')->first();
        $this->assertStringNotContainsString(self::TOKEN, $failed->payload);
        $this->assertStringNotContainsString(self::TOKEN, $failed->exception);
        $this->assertStringContainsString('mail server unreachable', $failed->exception);
    }
}
