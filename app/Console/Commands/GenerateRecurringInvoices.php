<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\RecurringInvoice;
use App\Services\RecurringInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Generates the draft invoices that recurring invoices have due.
 *
 * Safe to run at any time and as often as you like, including twice at once:
 * what is due depends only on dates, and RecurringInvoiceService generates each
 * occurrence under a row lock with a unique key behind it. Missed occurrences
 * (for example after downtime) are caught up oldest first.
 *
 * This is a system task with no current user or request: it walks the businesses
 * and gives each one to the service explicitly.
 */
class GenerateRecurringInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:generate-recurring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the draft invoices that recurring invoices have due';

    public function handle(RecurringInvoiceService $recurringInvoices): int
    {
        $today = today();
        $generated = 0;
        $failed = 0;

        Business::query()
            ->whereHas('recurringInvoices', fn (Builder $query) => $query->due($today))
            ->chunkById(100, function ($businesses) use ($recurringInvoices, $today, &$generated, &$failed) {
                foreach ($businesses as $business) {
                    $due = $business->recurringInvoices()->due($today)->orderBy('id')->get();

                    /** @var RecurringInvoice $recurring */
                    foreach ($due as $recurring) {
                        $result = $recurringInvoices->generateDue($business, $recurring);
                        $generated += count($result['invoices']);

                        if ($result['error'] !== null) {
                            $failed++;
                            $this->warn("Recurring invoice {$recurring->getKey()}: {$result['error']}");
                        }
                    }
                }
            });

        $this->info("Generated {$generated} invoice(s); {$failed} recurring invoice(s) failed.");

        return self::SUCCESS;
    }
}
