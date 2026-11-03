<?php

namespace App\Jobs;

use App\Ocr\Exceptions\OcrException;
use App\Ocr\Exceptions\OcrMalformedResponseException;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\ReceiptExtractionNormalizer;
use App\Ocr\ReceiptOcrProvider;
use App\Services\ExpenseReceiptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads one receipt with the OCR provider and saves what it found for the user to review.
 *
 * The payload is only the receipt ID. Everything else (business, file, entitlement) is read
 * fresh when the job runs, and a receipt is only read if ExpenseReceiptService::claim() moves
 * it from queued to processing: running the job again for a receipt that is processing, done,
 * discarded or failed does nothing. The business comes from the receipt row; this job never
 * uses CurrentBusiness, and it never creates an expense.
 *
 * Nothing from the receipt or the provider's answer is logged or kept beyond the normalized
 * fields. Only short, generic messages are stored as the error.
 */
class ProcessExpenseReceipt implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * Below the queue's retry_after (90s), so an attempt is never picked up twice.
     */
    public int $timeout = 60;

    public function __construct(public readonly int $expenseReceiptId) {}

    public function handle(ExpenseReceiptService $receipts, ReceiptOcrProvider $provider, ReceiptExtractionNormalizer $normalizer): void
    {
        $receipt = $receipts->claim($this->expenseReceiptId);

        if ($receipt === null) {
            return;
        }

        try {
            $normalized = $normalizer->normalize($provider->extract($receipts->document($receipt)));
        } catch (OcrPermanentException|OcrMalformedResponseException $e) {
            // The same input would fail the same way: fail it now; the user can retry or type it in.
            $receipts->markFailed($receipt->getKey(), $e->getMessage());

            return;
        } catch (Throwable $e) {
            // Temporary, or not certainly permanent: back to queued for the next attempt. When
            // the attempts run out the queue calls failed().
            $receipts->markForRetry($receipt, $e instanceof OcrException ? $e->getMessage() : 'The receipt could not be read. Please try again.');

            throw $e;
        }

        $receipts->markExtracted($receipt, $normalized);
    }

    /**
     * All attempts are used up.
     */
    public function failed(?Throwable $e): void
    {
        app(ExpenseReceiptService::class)->markFailed(
            $this->expenseReceiptId,
            $e instanceof OcrException ? $e->getMessage() : 'The receipt could not be read. Please try again.',
        );
    }
}
