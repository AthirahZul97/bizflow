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

    public function __construct(public readonly int $expenseReceiptId) {}

    public function handle(ExpenseReceiptService $receipts, ReceiptOcrProvider $provider, ReceiptExtractionNormalizer $normalizer): void
    {
        $receipt = $receipts->claim($this->expenseReceiptId);

        if ($receipt === null) {
            return;
        }

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
            // Temporary, or not certainly permanent: back to queued for the next attempt. On the
            // queue's last delivery there is no next one, and only this run still knows which claim
            // it holds (the queue's failed() hook gets a fresh instance that knows nothing), so
            // it ends the receipt here, atomically with proof of its claim.
            $message = $e instanceof OcrException ? $e->getMessage() : 'The receipt could not be read. Please try again.';

            try {
                if ($this->attempts() >= $this->tries) {
                    $receipts->markFailed($receipt->getKey(), $message, $receipt->attempts, $receipt->processing_started_at);
                } else {
                    $receipts->markForRetry($receipt, $message);
                }
            } catch (Throwable $recovery) {
                report($recovery);
            }

            throw $e;
        }
    }

    /**
     * The queue gave up on this job (its deliveries ran out, or it was killed). Laravel builds a
     * fresh instance from the payload for this call, so it holds no claim and may belong to a
     * dispatch that has long been superseded by a sweep or a newer worker. It therefore asks the
     * service for the claim-less failure only, which touches nothing but a receipt that has used
     * all its attempts and that nobody is running. Everything else is left to the sweeper, and a
     * run that did hold a claim on its last delivery has already ended the receipt in handle().
     */
    public function failed(?Throwable $e): void
    {
        try {
            app(ExpenseReceiptService::class)->markFailed(
                $this->expenseReceiptId,
                $e instanceof OcrException ? $e->getMessage() : 'The receipt could not be read. Please try again.',
            );
        } catch (Throwable $recovery) {
            // Nothing else can be done here; the sweeper finds the row and finishes the job.
            report($recovery);
        }
    }
}
