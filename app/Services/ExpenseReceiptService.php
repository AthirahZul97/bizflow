<?php

namespace App\Services;

use App\Billing\EntitlementGuard;
use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Enums\ExpenseReceiptStatus;
use App\Exceptions\EntitlementException;
use App\Exceptions\ExpenseReceiptException;
use App\Jobs\ProcessExpenseReceipt;
use App\Models\Business;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\NormalizedReceipt;
use App\Ocr\ReceiptDocument;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moves uploaded receipts through their states and is the only thing that writes
 * expense_receipts. It never creates an expense by itself: confirm() does, and only for a
 * receipt in "review" with the values the user submitted.
 *
 * Guarantees:
 * - Quota: a receipt holds one unit of the monthly OCR allowance (counted_at) from upload.
 *   Taking a unit (upload, or retry after a technical failure) happens under the business row
 *   lock after a fresh entitlement read, so concurrent requests at the limit cannot both pass.
 *   A technical failure, or a discard before extraction, releases the unit. Retrying or
 *   confirming a receipt that already holds its unit uses no more.
 * - Processing: only the conditional queued -> processing update starts a run, so two workers
 *   never read the same receipt at once. A "processing" row whose worker died (older than
 *   ocr.stale_processing_seconds) may be reclaimed; reading a receipt has no side effect beyond
 *   its cost, so that is safe. No receipt is sent to a provider more than ocr.max_attempts times.
 * - Confirming: under the receipt's row lock the status must be "review"; the expense, the
 *   expense_id link and the status change commit together or not at all, and expense_id is
 *   unique. Confirming again returns the existing expense.
 *
 * The business always comes from the caller (HTTP) or the receipt row (jobs); this service
 * never uses CurrentBusiness. uploaded_by and confirmed_by are audit metadata only.
 */
class ExpenseReceiptService
{
    /**
     * Attempts for these short transactions if MySQL picks them as a deadlock victim.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ReceiptFileInspector $inspector,
    ) {}

    /**
     * Whether receipt scanning is switched on (OCR_DRIVER is not "none").
     */
    public function isEnabled(): bool
    {
        return config('ocr.driver') !== 'none';
    }

    /**
     * Whether the configured provider is the demo one, whose output is not read from the receipt.
     */
    public function usesFakeProvider(): bool
    {
        return config('ocr.driver') === 'fake';
    }

    // ---- upload ---------------------------------------------------------------------------

    /**
     * Validate and store the file, take one unit of the monthly allowance and queue the OCR.
     *
     * @throws ValidationException for a file that is not an acceptable receipt
     * @throws EntitlementException when the plan has no OCR, no allowance is left or access is read-only
     */
    public function upload(Business $business, ?User $uploader, UploadedFile $file): ExpenseReceipt
    {
        if (! $this->isEnabled()) {
            throw new ExpenseReceiptException('Receipt scanning is not available.');
        }

        // Before the transaction: reading and decoding the file needs no lock.
        $inspected = $this->inspector->inspect($file);

        // Generated, never from the client: {business}/{ulid}.{detected extension}.
        $path = $business->getKey().'/'.strtolower((string) Str::ulid()).'.'.$inspected->extension;

        try {
            return DB::transaction(function () use ($business, $uploader, $inspected, $path) {
                EntitlementGuard::lock($business);

                // After the lock, and never from the memo: anything earlier may be stale.
                $check = $this->entitlements->fresh($business)->check(Entitlement::ReceiptOcr);

                if (! $check->allowed) {
                    throw EntitlementException::denied($check);
                }

                $receipt = $business->expenseReceipts()->make();
                $receipt->forceFill([
                    'uploaded_by' => $uploader?->getKey(),
                    'status' => ExpenseReceiptStatus::Queued,
                    'original_filename' => $inspected->displayName,
                    'mime_type' => $inspected->mimeType,
                    'size_bytes' => $inspected->size(),
                    'sha256' => $inspected->sha256,
                    'storage_path' => $path,
                    'attempts' => 0,
                    'counted_at' => now(),
                    'queued_at' => now(),
                ])->save();

                $receipt->disk()->put($path, $inspected->contents);

                // ProcessExpenseReceipt is ShouldQueueAfterCommit: it is only queued if this commits.
                ProcessExpenseReceipt::dispatch($receipt->getKey());

                return $receipt;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (Throwable $e) {
            // The row rolled back (or never existed): don't leave an orphan file behind.
            Storage::disk(ExpenseReceipt::DISK)->delete($path);

            throw $e;
        }
    }

    // ---- processing (called by the job) -----------------------------------------------------

    /**
     * Claim a receipt for one OCR run. Returns it only if this call moved it to "processing";
     * otherwise null, and nothing may be read.
     */
    public function claim(int $receiptId): ?ExpenseReceipt
    {
        return DB::transaction(function () use ($receiptId) {
            $receipt = ExpenseReceipt::query()->whereKey($receiptId)->lockForUpdate()->first();

            if ($receipt === null) {
                return null;
            }

            if ($receipt->status === ExpenseReceiptStatus::Processing) {
                // Another worker has it, unless that attempt is old enough to be dead.
                $started = $receipt->processing_started_at;

                if ($started !== null && $started->gt(now()->subSeconds((int) config('ocr.stale_processing_seconds')))) {
                    return null;
                }
            } elseif ($receipt->status !== ExpenseReceiptStatus::Queued) {
                return null;
            }

            if ($receipt->attempts >= (int) config('ocr.max_attempts')) {
                $this->finishFailed($receipt, 'This receipt has been read too many times without success. Enter the details by hand.');

                return null;
            }

            $business = $receipt->business;

            // Entitlement can be lost between upload and processing (a lapse or a downgrade).
            // The receipt already holds its unit of allowance, so only inclusion and write access
            // are checked. Fail it for good: retrying later would use a plan that no longer allows it.
            $entitled = $this->entitlements->fresh($business)->check(Entitlement::ReceiptOcr, 0);

            if (! $entitled->allowed) {
                $this->finishFailed($receipt, 'Not read: '.$entitled->message());

                return null;
            }

            $claimed = ExpenseReceipt::query()
                ->whereKey($receipt->getKey())
                ->where('status', $receipt->status->value)
                ->update([
                    'status' => ExpenseReceiptStatus::Processing->value,
                    'attempts' => $receipt->attempts + 1,
                    'processing_started_at' => now(),
                    'updated_at' => now(),
                ]);

            return $claimed === 1 ? $receipt->refresh() : null;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * The receipt's file as a provider document. Fails permanently if the file is gone or no
     * longer matches the hash recorded at upload.
     *
     * @throws OcrPermanentException
     */
    public function document(ExpenseReceipt $receipt): ReceiptDocument
    {
        if ($receipt->file_deleted_at !== null || ! $receipt->disk()->exists($receipt->storage_path)) {
            throw new OcrPermanentException('The receipt file is no longer available.');
        }

        $contents = $receipt->disk()->get($receipt->storage_path);

        if (! is_string($contents) || ! hash_equals($receipt->sha256, hash('sha256', $contents))) {
            throw new OcrPermanentException('The receipt file could not be verified.');
        }

        return new ReceiptDocument($contents, $receipt->mime_type, $receipt->sha256);
    }

    /**
     * Save a run's result: "review" when there is a usable total, "unreadable" when there is
     * not. Does nothing if the receipt is no longer processing (discarded meanwhile, say).
     */
    public function markExtracted(ExpenseReceipt $receipt, NormalizedReceipt $result): void
    {
        ExpenseReceipt::query()
            ->whereKey($receipt->getKey())
            ->where('status', ExpenseReceiptStatus::Processing->value)
            ->update([
                'status' => ($result->isUsable() ? ExpenseReceiptStatus::Review : ExpenseReceiptStatus::Unreadable)->value,
                'provider' => mb_substr($result->provider, 0, 30),
                'provider_model' => $result->model === null ? null : mb_substr($result->model, 0, 60),
                'extraction' => json_encode($result->toArray(), JSON_THROW_ON_ERROR),
                'extracted_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Put a receipt whose attempt failed temporarily back in the queue for the next attempt.
     */
    public function markForRetry(ExpenseReceipt $receipt, string $error): void
    {
        ExpenseReceipt::query()
            ->whereKey($receipt->getKey())
            ->where('status', ExpenseReceiptStatus::Processing->value)
            ->update([
                'status' => ExpenseReceiptStatus::Queued->value,
                'last_error' => mb_substr($error, 0, 500),
                'updated_at' => now(),
            ]);
    }

    /**
     * Record that reading failed for good, and release the receipt's unit of allowance. Does
     * nothing if the receipt already moved on.
     */
    public function markFailed(int $receiptId, string $error): void
    {
        ExpenseReceipt::query()
            ->whereKey($receiptId)
            ->whereIn('status', [ExpenseReceiptStatus::Queued->value, ExpenseReceiptStatus::Processing->value])
            ->update([
                'status' => ExpenseReceiptStatus::Failed->value,
                'failed_at' => now(),
                'last_error' => mb_substr($error, 0, 500),
                'counted_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function finishFailed(ExpenseReceipt $receipt, string $error): void
    {
        $this->markFailed($receipt->getKey(), $error);
    }

    // ---- user actions -----------------------------------------------------------------------

    /**
     * Run the OCR again on a failed, unreadable or reviewed receipt.
     *
     * A failed receipt holds no allowance, so it must take a unit again (checked under the lock);
     * the others keep the unit they already hold and use no more. Bounded by ocr.max_attempts.
     *
     * @throws ExpenseReceiptException
     * @throws EntitlementException
     */
    public function retry(Business $business, ExpenseReceipt $receipt): void
    {
        $this->ensureBelongsTo($receipt, $business);

        DB::transaction(function () use ($business, $receipt) {
            EntitlementGuard::lock($business);

            $locked = $business->expenseReceipts()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ExpenseReceiptStatus::retryable(), true)) {
                throw new ExpenseReceiptException('This receipt can’t be read again right now.');
            }

            if ($locked->file_deleted_at !== null || ! $locked->disk()->exists($locked->storage_path)) {
                throw new ExpenseReceiptException('The receipt file is no longer available.');
            }

            if ($locked->attempts >= (int) config('ocr.max_attempts')) {
                throw new ExpenseReceiptException('This receipt has been read too many times. Enter the details by hand instead.');
            }

            $needsUnit = $locked->counted_at === null;
            $check = $this->entitlements->fresh($business)->check(Entitlement::ReceiptOcr, $needsUnit ? 1 : 0);

            if (! $check->allowed) {
                throw EntitlementException::denied($check);
            }

            $queued = ExpenseReceipt::query()
                ->whereKey($locked->getKey())
                ->whereIn('status', array_map(fn (ExpenseReceiptStatus $s) => $s->value, ExpenseReceiptStatus::retryable()))
                ->update([
                    'status' => ExpenseReceiptStatus::Queued->value,
                    'counted_at' => $locked->counted_at ?? now(),
                    'queued_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            if ($queued !== 1) {
                throw new ExpenseReceiptException('This receipt can’t be read again right now.');
            }

            ProcessExpenseReceipt::dispatch($locked->getKey());
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Let the user fill the form in by hand for a receipt whose OCR failed or found nothing.
     * Uses no OCR allowance and calls no provider.
     *
     * @throws ExpenseReceiptException
     */
    public function useManualEntry(Business $business, ExpenseReceipt $receipt): void
    {
        $this->ensureBelongsTo($receipt, $business);

        $changed = DB::transaction(function () use ($business, $receipt) {
            $locked = $business->expenseReceipts()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [ExpenseReceiptStatus::Failed, ExpenseReceiptStatus::Unreadable], true)) {
                return 0;
            }

            return ExpenseReceipt::query()
                ->whereKey($locked->getKey())
                ->where('status', $locked->status->value)
                ->update([
                    'status' => ExpenseReceiptStatus::Review->value,
                    'extraction' => json_encode(['fields' => [], 'warnings' => [], 'manual' => true], JSON_THROW_ON_ERROR),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
        }, self::TRANSACTION_ATTEMPTS);

        if ($changed !== 1) {
            throw new ExpenseReceiptException('Manual entry is only available for receipts that could not be read.');
        }
    }

    /**
     * Throw away an unconfirmed receipt (or a confirmed one whose expense was deleted): it is
     * marked discarded, its OCR data cleared and its file deleted. A receipt that was never
     * extracted gives its unit of allowance back; one that was read keeps it.
     *
     * @throws ExpenseReceiptException
     */
    public function discard(Business $business, ExpenseReceipt $receipt): void
    {
        $this->ensureBelongsTo($receipt, $business);

        $this->discardRow($business, $receipt->getKey());
    }

    /**
     * Confirm a receipt: create the expense from the values the user submitted.
     *
     * Everything below the receipt lock is one transaction, so a failure anywhere leaves the
     * receipt in "review" and no expense behind. A receipt that is already confirmed returns its
     * expense and creates nothing (a double click or a replayed request).
     *
     * @param  array<string, mixed>  $values  validated ExpenseRequest data (never business_id or created_by)
     * @return array{0: Expense, 1: bool} the expense, and whether this call created it
     *
     * @throws ExpenseReceiptException
     * @throws EntitlementException
     */
    public function confirm(Business $business, ExpenseReceipt $receipt, User $confirmer, array $values): array
    {
        $this->ensureBelongsTo($receipt, $business);

        return DB::transaction(function () use ($business, $receipt, $confirmer, $values) {
            $locked = $business->expenseReceipts()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ExpenseReceiptStatus::Confirmed && $locked->expense_id !== null) {
                $existing = $business->expenses()->find($locked->expense_id);

                if ($existing === null) {
                    throw new ExpenseReceiptException('This receipt was already confirmed, but its expense has since been deleted.');
                }

                return [$existing, false];
            }

            if ($locked->status !== ExpenseReceiptStatus::Review) {
                throw new ExpenseReceiptException('This receipt isn’t ready to be confirmed.');
            }

            // Read fresh, after the lock: a lapse since the page was opened must stop the write.
            if (! $this->entitlements->fresh($business)->canWrite()) {
                throw EntitlementException::readOnly();
            }

            $expense = $business->expenses()->make($values);
            $expense->forceFill(['created_by' => $confirmer->getKey()])->save();

            $linked = ExpenseReceipt::query()
                ->whereKey($locked->getKey())
                ->where('status', ExpenseReceiptStatus::Review->value)
                ->update([
                    'status' => ExpenseReceiptStatus::Confirmed->value,
                    'expense_id' => $expense->getKey(),
                    'confirmed_by' => $confirmer->getKey(),
                    'confirmed_at' => now(),
                    'edited_fields' => json_encode($this->editedFields($locked, $expense), JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

            if ($linked !== 1) {
                // Rolls the expense back with it.
                throw new ExpenseReceiptException('This receipt isn’t ready to be confirmed.');
            }

            return [$expense, true];
        }, self::TRANSACTION_ATTEMPTS);
    }

    // ---- duplicate warnings (never blocks) ---------------------------------------------------

    /**
     * An earlier, undiscarded receipt of this business with the same file contents.
     */
    public function duplicateFile(Business $business, ExpenseReceipt $receipt): ?ExpenseReceipt
    {
        return $business->expenseReceipts()
            ->where('sha256', $receipt->sha256)
            ->where('id', '<', $receipt->getKey())
            ->where('status', '!=', ExpenseReceiptStatus::Discarded->value)
            ->orderBy('id')
            ->first();
    }

    /**
     * An existing expense with the same payee, date and amount as the suggested values.
     *
     * @param  array{expense_date?: ?string, amount?: ?string, payee?: ?string}  $values
     */
    public function similarExpense(Business $business, array $values): ?Expense
    {
        if (empty($values['expense_date']) || empty($values['amount']) || empty($values['payee'])) {
            return null;
        }

        return $business->expenses()
            ->whereDate('expense_date', $values['expense_date'])
            ->where('amount', $values['amount'])
            ->whereRaw('lower(payee) = ?', [mb_strtolower($values['payee'])])
            ->orderBy('id')
            ->first();
    }

    // ---- pruning ------------------------------------------------------------------------------

    /**
     * Discard the business's receipts that nobody confirmed within ocr.prune_after_days, and
     * delete the files of discarded ones whose file is still there. Confirmed receipts are never
     * touched. A business that cannot write is skipped (read-only never deletes), returning null.
     *
     * Safe to run at any time, more than once, and concurrently: each receipt is discarded by a
     * conditional update under its row lock.
     *
     * @return int|null how many receipts were discarded, or null when the business was skipped
     */
    public function prune(Business $business, ?CarbonImmutable $now = null): ?int
    {
        if (! $this->entitlements->fresh($business)->canWrite()) {
            return null;
        }

        $cutoff = ($now ?? CarbonImmutable::now())->subDays((int) config('ocr.prune_after_days'));
        $discarded = 0;

        $stale = $business->expenseReceipts()
            ->whereIn('status', array_map(fn (ExpenseReceiptStatus $s) => $s->value, ExpenseReceiptStatus::unconfirmed()))
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id');

        foreach ($stale as $id) {
            try {
                $this->discardRow($business, $id);
                $discarded++;
            } catch (ExpenseReceiptException) {
                // It moved on (confirmed or discarded) since it was listed: leave it alone.
            }
        }

        // Finish any earlier discard whose file could not be deleted at the time.
        $business->expenseReceipts()
            ->where('status', ExpenseReceiptStatus::Discarded->value)
            ->whereNull('file_deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => $this->purgeFile($id));

        return $discarded;
    }

    // ---- internals ----------------------------------------------------------------------------

    private function discardRow(Business $business, int $receiptId): void
    {
        DB::transaction(function () use ($business, $receiptId) {
            $locked = $business->expenseReceipts()->whereKey($receiptId)->lockForUpdate()->firstOrFail();

            $discardable = in_array($locked->status, ExpenseReceiptStatus::discardable(), true)
                || ($locked->status === ExpenseReceiptStatus::Confirmed && $locked->expense_id === null);

            if (! $discardable) {
                throw new ExpenseReceiptException('This receipt can’t be discarded.');
            }

            $updated = ExpenseReceipt::query()
                ->whereKey($locked->getKey())
                ->where('status', $locked->status->value)
                ->update([
                    'status' => ExpenseReceiptStatus::Discarded->value,
                    'discarded_at' => now(),
                    'extraction' => null,
                    // A receipt that never reached a provider result gives its unit back.
                    'counted_at' => $locked->extracted_at === null ? null : $locked->counted_at,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ExpenseReceiptException('This receipt can’t be discarded.');
            }
        }, self::TRANSACTION_ATTEMPTS);

        // After the status change has committed: if this fails, the receipt is already
        // discarded and the next prune run deletes the file.
        $this->purgeFile($receiptId);
    }

    private function purgeFile(int $receiptId): void
    {
        $receipt = ExpenseReceipt::query()->whereKey($receiptId)->where('status', ExpenseReceiptStatus::Discarded->value)->first();

        if ($receipt === null || $receipt->file_deleted_at !== null) {
            return;
        }

        try {
            $receipt->disk()->delete($receipt->storage_path);
        } catch (Throwable) {
            return;
        }

        ExpenseReceipt::query()->whereKey($receiptId)->whereNull('file_deleted_at')->update(['file_deleted_at' => now()]);
    }

    /**
     * The expense fields whose submitted value differs from what the receipt suggested.
     * Names only: no values are kept.
     *
     * @return list<string>
     */
    private function editedFields(ExpenseReceipt $receipt, Expense $expense): array
    {
        $suggested = $receipt->prefill();
        $final = [
            'expense_date' => $expense->expense_date?->toDateString(),
            'category' => $expense->category?->value,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'payee' => $expense->payee,
            'notes' => $expense->notes,
        ];
        $edited = [];

        foreach ($final as $field => $value) {
            $before = $suggested[$field];

            $same = $field === 'amount' && $before !== null && is_numeric($before)
                ? BigDecimal::of((string) $value)->isEqualTo(BigDecimal::of($before))
                : ($this->blankToNull($before) === $this->blankToNull($value));

            if (! $same) {
                $edited[] = $field;
            }
        }

        return $edited;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function ensureBelongsTo(ExpenseReceipt $receipt, Business $business): void
    {
        if ($receipt->business_id !== $business->getKey()) {
            // A programming error: callers authorize the receipt against the current business first.
            throw new \LogicException('The receipt does not belong to the business.');
        }
    }
}
