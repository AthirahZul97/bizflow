<?php

namespace App\Console\Commands;

use App\Enums\ExpenseReceiptStatus;
use App\Models\Business;
use App\Services\ExpenseReceiptService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Discards receipts nobody confirmed within the retention period (ocr.prune_after_days) and
 * deletes their files. Confirmed receipts, and the files kept with their expenses, are never
 * touched.
 *
 * Safe to run at any time, more than once, and concurrently: each receipt is discarded by a
 * conditional update under its row lock, so a second run finds nothing left to do. A system task
 * with no current user or request: it walks the businesses and gives each to the service
 * explicitly. A business whose subscription does not allow writes is skipped untouched, because
 * a read-only subscription never deletes business data; its receipts are cleaned up once it can
 * write again.
 */
class PruneExpenseReceipts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'receipts:prune';

    /**
     * @var string
     */
    protected $description = 'Discard receipts nobody confirmed within the retention period and delete their files';

    public function handle(ExpenseReceiptService $receipts): int
    {
        $cutoff = now()->subDays((int) config('ocr.prune_after_days'));
        $unconfirmed = array_map(fn (ExpenseReceiptStatus $s) => $s->value, ExpenseReceiptStatus::unconfirmed());
        $discarded = 0;
        $skipped = 0;

        Business::query()
            ->whereHas('expenseReceipts', fn (Builder $query) => $query->where(function (Builder $query) use ($cutoff, $unconfirmed) {
                $query->where(fn (Builder $q) => $q->whereIn('status', $unconfirmed)->where('created_at', '<', $cutoff))
                    ->orWhere(fn (Builder $q) => $q->where('status', ExpenseReceiptStatus::Discarded->value)->whereNull('file_deleted_at'));
            }))
            ->chunkById(100, function ($businesses) use ($receipts, &$discarded, &$skipped) {
                foreach ($businesses as $business) {
                    $count = $receipts->prune($business);

                    if ($count === null) {
                        $skipped++;
                        $this->line("Business {$business->getKey()} skipped: its subscription does not allow changes.");

                        continue;
                    }

                    $discarded += $count;
                }
            });

        $this->info("Discarded {$discarded} unconfirmed receipt(s).");

        if ($skipped > 0) {
            $this->info("Skipped {$skipped} business(es) whose subscription does not allow changes.");
        }

        return self::SUCCESS;
    }
}
