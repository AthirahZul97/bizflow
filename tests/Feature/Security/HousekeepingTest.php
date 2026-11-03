<?php

namespace Tests\Feature\Security;

use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Invoices\CreatesInvoices;
use Tests\Feature\Invoices\SendsInvoiceEmails;
use Tests\TestCase;

/**
 * Scheduled housekeeping touches framework logs and nothing else: it can never alter a business
 * record, and the schedule keeps the work that was already there.
 */
class HousekeepingTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;
    use SendsInvoiceEmails;

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        return array_map(fn ($event) => (string) $event->command, app(Schedule::class)->events());
    }

    public function test_failed_jobs_are_pruned_daily_and_the_existing_schedule_is_unchanged(): void
    {
        $commands = implode("\n", $this->scheduledCommands());

        $this->assertStringContainsString('queue:prune-failed --hours=168', $commands);
        $this->assertStringContainsString('invoices:generate-recurring', $commands);
        $this->assertStringContainsString('receipts:recover', $commands);
        $this->assertStringContainsString('receipts:prune', $commands);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'queue:prune-failed'));
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function failedJob(string $failedAt): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\SendInvoiceEmail"}',
            'exception' => 'RuntimeException: boom',
            'failed_at' => $failedAt,
        ]);
    }

    /**
     * A fingerprint of every table except the one housekeeping is allowed to change.
     *
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $prints = [];

        foreach (Schema::getTableListing() as $table) {
            $name = Str::afterLast($table, '.');

            if ($name === 'failed_jobs') {
                continue;
            }

            $prints[$name] = md5(DB::table($name)->get()->toJson());
        }

        return $prints;
    }

    public function test_pruning_failed_jobs_removes_only_old_failed_job_rows(): void
    {
        $this->setUpReceiptsSafely();
        $user = User::factory()->create();
        $this->emailableInvoice($user);
        ExpenseReceipt::factory()->create(['business_id' => $this->businessOf($user)->getKey()]);

        $this->failedJob(now()->subHours(200)->toDateTimeString());
        $this->failedJob(now()->subHours(169)->toDateTimeString());
        $this->failedJob(now()->subHours(2)->toDateTimeString());

        $before = $this->fingerprint();
        $this->assertGreaterThan(8, count(array_filter($before, fn ($print, $table) => DB::table($table)->count() > 0, ARRAY_FILTER_USE_BOTH)), 'the fixture has business data in many tables');

        $this->artisan('queue:prune-failed --hours=168')->assertExitCode(0);

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'only the recent failure is kept');
        $this->assertSame($before, $this->fingerprint(), 'no other table changed in any way');
    }

    private function setUpReceiptsSafely(): void
    {
        Storage::fake(ExpenseReceipt::DISK);
    }
}
