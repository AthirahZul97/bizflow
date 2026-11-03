<?php

namespace App\Console\Commands;

use App\Services\ExpenseReceiptService;
use Illuminate\Console\Command;

/**
 * Re-dispatches OCR work that nobody is doing: receipts whose worker died while "processing"
 * and receipts whose job was lost while "queued". Receipts that have used all their attempts
 * are failed for good (their allowance is released and their file kept).
 *
 * Safe to run at any time, more than once, and concurrently: a run takes the right to dispatch
 * each receipt with a conditional update, a live claim is never touched, and dispatching twice
 * is harmless because claim() decides. It reads no receipt and calls no provider itself.
 */
class RecoverStuckReceipts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'receipts:recover';

    /**
     * @var string
     */
    protected $description = 'Re-dispatch receipts whose OCR worker died or whose job was lost, and fail those out of attempts';

    public function handle(ExpenseReceiptService $receipts): int
    {
        $result = $receipts->recoverStuck();

        $this->info("Re-dispatched {$result['redispatched']} receipt(s), failed {$result['failed']} out of attempts.");

        if ($result['dispatch_errors'] > 0) {
            $this->error("{$result['dispatch_errors']} receipt(s) could not be dispatched; they will be tried again on the next run.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
