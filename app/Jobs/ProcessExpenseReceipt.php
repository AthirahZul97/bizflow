<?php

namespace App\Jobs;

use App\Ocr\Exceptions\OcrException;
use App\Ocr\Exceptions\OcrMalformedResponseException;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\ReceiptExtractionNormalizer;
use App\Ocr\ReceiptOcrProvider;
use App\Services\ExpenseReceiptService;
use Carbon\CarbonInterface;
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
 * A job that ends without work (the claim is still live, or the receipt is done) is safe only
 * because receipts:recover re-dispatches work that is still outstanding; it is the backstop
 * for a worker that dies or a job that is lost.
 *
 * Every result is written under the claim's generation (the receipt's attempts after claim()),
 * so a worker whose claim was reclaimed cannot overwrite the current attempt. Recovery steps
 * never replace the failure that triggered them: if one throws, it is reported and the original
 * exception is thrown, so the queue retries and finally calls failed().
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

    /**
     * The claim this run holds (its generation and start), so failed() can fail exactly that
     * claim and nothing a newer attempt or a recovery has since taken over.
     */
    private ?int $claimedAttempt = null;

    private ?CarbonInterface $claimedStartedAt = null;

    public function __construct(public readonly int $expenseReceiptId) {}

    public function handle(ExpenseReceiptService $receipts, ReceiptOcrProvider $provider, ReceiptExtractionNormalizer $normalizer): void
    {
        $receipt = $receipts->claim($this->expenseReceiptId);

        if ($receipt === null) {
            return;
        }

        $this->claimedAttempt = $receipt->attempts;
        $this->claimedStartedAt = $receipt->processing_started_at;

        try {
            $normalized = $normalizer->normalize($provider->extract($receipts->document($receipt)));

            // Inside the guarded block: a failure to save the result is recoverable like any other.
            $receipts->markExtracted($receipt, $normalized);
        } catch (OcrPermanentException|OcrMalformedResponseException $e) {
            // The same input would fail the same way: fail it now; the user can retry or type it in.
            try {
                $receipts->markFailed($receipt->getKey(), $e->getMessage(), $receipt->attempts, $receipt->processing_started_at);
            } catch (Throwable $recovery) {
                // The row is still "processing"; let the queue retry (and failed()) and the
                // sweeper recover it. The original failure is the one that surfaces.
                report($recovery);

                throw $e;
            }
        } catch (Throwable $e) {
            // Temporary, or not certainly permanent: back to queued for the next attempt. When
            // the attempts run out the queue calls failed().
            try {
                $receipts->markForRetry($receipt, $e instanceof OcrException ? $e->getMessage() : 'The receipt could not be read. Please try again.');
            } catch (Throwable $recovery) {
                report($recovery);
            }

            throw $e;
        }
    }

    /**
     * All attempts are used up.
     */
    public function failed(?Throwable $e): void
    {
        try {
            app(ExpenseReceiptService::class)->markFailed(
                $this->expenseReceiptId,
                $e instanceof OcrException ? $e->getMessage() : 'The receipt could not be read. Please try again.',
                $this->claimedAttempt,
                $this->claimedStartedAt,
            );
        } catch (Throwable $recovery) {
            // Nothing else can be done here; the sweeper finds the row and finishes the job.
            report($recovery);
        }
    }
}
