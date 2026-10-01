<?php

namespace App\Services;

use App\Enums\RecurringInvoiceStatus;
use App\Exceptions\InvoiceCalculationException;
use App\Exceptions\RecurringInvoiceException;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The only place recurring invoices are written and the only place invoices are
 * generated from them.
 *
 * Generation creates ordinary draft invoices through InvoiceService::saveDraft();
 * there is no second way of writing invoices. A generated invoice is never
 * changed afterwards by its recurring invoice.
 *
 * One occurrence is generated per transaction:
 *   1. lock the recurring invoice's row (serializes the scheduler, overlapping
 *      runs, several workers and "Generate now" for that schedule),
 *   2. re-check that it is active and has an occurrence due,
 *   3. create the draft through InvoiceService,
 *   4. link it to the schedule and the occurrence date,
 *   5. advance next_occurrence_on.
 * The unique (recurring_invoice_id, recurring_occurrence_on) key on invoices is
 * the final guarantee that an occurrence is never generated twice.
 *
 * Every method receives the Business explicitly and reaches recurring invoices,
 * customers and products only through it.
 */
class RecurringInvoiceService
{
    /**
     * The most occurrences one schedule catches up on in a single run; the next
     * run continues with the rest.
     */
    public const MAX_OCCURRENCES_PER_RUN = 12;

    /**
     * Attempts for write transactions if MySQL picks one as a deadlock victim.
     */
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /**
     * Create or update a recurring invoice from validated input.
     *
     * Lines are normalised by InvoiceService::resolveLines() (the customer and any
     * products must belong to the business) and checked by InvoiceCalculator, the
     * same as an invoice. The start date and frequency can only change until the
     * first invoice has been generated.
     *
     * @param  array<string, mixed>  $data  Validated RecurringInvoiceRequest data.
     */
    public function save(Business $business, ?User $creator, array $data, ?RecurringInvoice $recurring = null): RecurringInvoice
    {
        return DB::transaction(function () use ($business, $creator, $data, $recurring) {
            if ($recurring === null) {
                $recurring = $business->recurringInvoices()->make();
                $recurring->forceFill([
                    'status' => RecurringInvoiceStatus::Active,
                    'created_by' => $creator?->getKey(),
                ]);
            } else {
                $recurring = $this->locked($business, $recurring);

                if ($recurring->isCancelled()) {
                    throw new RecurringInvoiceException('Cancelled recurring invoices can’t be edited.');
                }
            }

            $customer = $business->customers()->findOrFail($data['customer_id']);
            $lines = $this->invoices->resolveLines($business, $data['items']);
            $this->calculator->calculate($lines, $data['discount_amount'] ?? '0', $data['tax_rate'] ?? '0');

            $recurring->fill([
                'name' => $data['name'],
                'payment_terms_days' => $data['payment_terms_days'],
                'discount_amount' => $data['discount_amount'] ?? '0',
                'tax_label' => $data['tax_label'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? '0',
                'notes' => $data['notes'] ?? null,
            ]);
            $recurring->forceFill([
                'customer_id' => $customer->id,
                'end_date' => $data['end_date'] ?? null,
            ]);

            if (! $recurring->hasGenerated()) {
                $recurring->forceFill([
                    'frequency' => $data['frequency'],
                    'start_date' => $data['start_date'],
                    'next_occurrence_on' => $data['start_date'],
                ]);
            } elseif ($recurring->end_date !== null && $recurring->end_date->lt($recurring->last_occurrence_on)) {
                throw new RecurringInvoiceException('The end date cannot be before the last invoice already generated.');
            }

            $isNew = ! $recurring->exists;
            $recurring->save();

            // As for invoices: a new record has no lines, and skipping the delete avoids a MySQL gap lock.
            if (! $isNew) {
                $recurring->items()->reorder()->delete();
            }

            foreach ($lines as $index => $line) {
                $item = $recurring->items()->make($line);
                $item->forceFill(['position' => $index + 1])->save();
            }

            return $recurring->load('items');
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function pause(Business $business, RecurringInvoice $recurring): RecurringInvoice
    {
        return $this->transition($business, $recurring, RecurringInvoiceStatus::Paused,
            ['paused_at' => now()], 'Only active recurring invoices can be paused.');
    }

    /**
     * Resume a paused schedule. Occurrences that fell due while it was paused are
     * skipped: the next occurrence becomes the first one on or after today.
     */
    public function resume(Business $business, RecurringInvoice $recurring): RecurringInvoice
    {
        return DB::transaction(function () use ($business, $recurring) {
            $recurring = $this->locked($business, $recurring);

            if (! $recurring->isPaused()) {
                throw new RecurringInvoiceException('Only paused recurring invoices can be resumed.');
            }

            $next = $recurring->schedule()->onOrAfter(today());

            $recurring->forceFill([
                'status' => RecurringInvoiceStatus::Active,
                'paused_at' => null,
                'next_occurrence_on' => $next->gt($recurring->next_occurrence_on) ? $next : $recurring->next_occurrence_on,
            ])->save();

            return $recurring;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Cancel a schedule for good. Invoices it generated are untouched.
     */
    public function cancel(Business $business, RecurringInvoice $recurring): RecurringInvoice
    {
        return $this->transition($business, $recurring, RecurringInvoiceStatus::Cancelled,
            ['cancelled_at' => now()], 'This recurring invoice is already cancelled.');
    }

    /**
     * Delete a schedule that has never generated an invoice. One that has can only be cancelled.
     */
    public function delete(Business $business, RecurringInvoice $recurring): void
    {
        DB::transaction(function () use ($business, $recurring) {
            $recurring = $this->locked($business, $recurring);

            if ($recurring->hasGenerated()) {
                throw new RecurringInvoiceException('This recurring invoice has generated invoices and can’t be deleted. Cancel it instead.');
            }

            $recurring->delete();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Generate the next occurrence if one is due today or earlier.
     *
     * Returns the new draft invoice, or null when nothing is due (not active,
     * finished, not yet due, or the occurrence was generated by someone else in
     * the meantime). Throws if the invoice cannot be created; nothing is kept and
     * the schedule does not advance.
     */
    public function generateNext(Business $business, RecurringInvoice|int $recurring, ?User $creator = null): ?Invoice
    {
        $id = $recurring instanceof RecurringInvoice ? $recurring->getKey() : $recurring;

        try {
            return DB::transaction(function () use ($business, $id, $creator) {
                $recurring = $business->recurringInvoices()->whereKey($id)->lockForUpdate()->first();

                if ($recurring === null || ! $recurring->isDue(today())) {
                    return null;
                }

                $occurrence = $recurring->next_occurrence_on->toImmutable();
                $recurring->load('items.product');
                $this->ensureProductsUsable($recurring);

                $invoice = $this->invoices->saveDraft($business, $creator, $this->invoiceData($recurring, $occurrence));
                $invoice->forceFill([
                    'recurring_invoice_id' => $recurring->getKey(),
                    'recurring_occurrence_on' => $occurrence->toDateString(),
                ])->save();

                $recurring->forceFill([
                    'next_occurrence_on' => $recurring->schedule()->after($occurrence),
                    'last_occurrence_on' => $occurrence,
                    'last_generated_at' => now(),
                    'last_generation_error' => null,
                    'last_generation_failed_at' => null,
                ])->save();

                return $invoice;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            // The database refused a second invoice for this occurrence, so it already
            // exists and the pointer was stale. Move past it; nothing was created.
            $this->advancePastGeneratedOccurrences($business, $id);

            return null;
        }
    }

    /**
     * Generate every occurrence that is due, oldest first, one transaction each,
     * up to $max. A failure is recorded on the schedule and stops this run; the
     * next run retries from the same occurrence.
     *
     * @return array{invoices: list<Invoice>, error: string|null}
     */
    public function generateDue(Business $business, RecurringInvoice $recurring, ?User $creator = null, int $max = self::MAX_OCCURRENCES_PER_RUN): array
    {
        $invoices = [];

        for ($i = 0; $i < $max; $i++) {
            try {
                $invoice = $this->generateNext($business, $recurring->getKey(), $creator);
            } catch (Throwable $e) {
                return ['invoices' => $invoices, 'error' => $this->recordFailure($business, $recurring->getKey(), $e)];
            }

            if ($invoice === null) {
                break;
            }

            $invoices[] = $invoice;
        }

        return ['invoices' => $invoices, 'error' => null];
    }

    /**
     * "Generate now": generate the next occurrence, which must be due today or
     * earlier. Uses exactly the same path as the scheduler.
     */
    public function generateNow(Business $business, RecurringInvoice $recurring, User $user): Invoice
    {
        try {
            $invoice = $this->generateNext($business, $recurring->getKey(), $user);
        } catch (Throwable $e) {
            throw new RecurringInvoiceException($this->recordFailure($business, $recurring->getKey(), $e));
        }

        if ($invoice === null) {
            throw new RecurringInvoiceException('There is no invoice due to generate right now.');
        }

        return $invoice;
    }

    /**
     * The totals one generated invoice would have, or null if the template cannot be calculated.
     */
    public function totals(RecurringInvoice $recurring): ?InvoiceTotals
    {
        try {
            return $this->calculator->calculate(
                $recurring->items->map(fn ($item) => ['quantity' => $item->quantity, 'unit_price' => $item->unit_price])->all(),
                $recurring->discount_amount,
                $recurring->tax_rate,
            );
        } catch (InvoiceCalculationException) {
            return null;
        }
    }

    /**
     * The saveDraft() input for one occurrence: the invoice is dated on the
     * occurrence and due after the payment terms.
     *
     * @return array<string, mixed>
     */
    private function invoiceData(RecurringInvoice $recurring, CarbonImmutable $occurrence): array
    {
        return [
            'customer_id' => $recurring->customer_id,
            'issue_date' => $occurrence->toDateString(),
            'due_date' => $occurrence->addDays($recurring->payment_terms_days)->toDateString(),
            'discount_amount' => $recurring->discount_amount,
            'tax_label' => $recurring->tax_label,
            'tax_rate' => $recurring->tax_rate,
            'notes' => $recurring->notes,
            'items' => $recurring->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->name,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->all(),
        ];
    }

    /**
     * Fail with a message naming the product, rather than InvoiceService's general one.
     */
    private function ensureProductsUsable(RecurringInvoice $recurring): void
    {
        foreach ($recurring->items as $item) {
            if ($item->product !== null && ! $item->product->is_active) {
                throw new RecurringInvoiceException(
                    "The product “{$item->product->name}” is inactive. Reactivate it, or remove it from this recurring invoice."
                );
            }
        }
    }

    /**
     * Store why generation failed, so the owner can see and fix it. Returns the message.
     * Runs outside the failed transaction, which has been rolled back.
     */
    private function recordFailure(Business $business, int $id, Throwable $e): string
    {
        $expected = $e instanceof RecurringInvoiceException
            || $e instanceof InvoiceCalculationException
            || $e instanceof InvalidArgumentException;

        if (! $expected) {
            report($e);
        }

        $message = $expected
            ? $e->getMessage()
            : 'The invoice could not be generated because of an unexpected error. It will be tried again automatically.';
        $message = mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 500, '…');

        $business->recurringInvoices()->whereKey($id)->update([
            'last_generation_error' => $message,
            'last_generation_failed_at' => now(),
        ]);

        return $message;
    }

    /**
     * Move next_occurrence_on past occurrences that already have an invoice.
     */
    private function advancePastGeneratedOccurrences(Business $business, int $id): void
    {
        DB::transaction(function () use ($business, $id) {
            $recurring = $business->recurringInvoices()->whereKey($id)->lockForUpdate()->first();

            if ($recurring === null) {
                return;
            }

            $latest = $recurring->invoices()->max('recurring_occurrence_on');

            if ($latest === null || CarbonImmutable::parse($latest)->lt($recurring->next_occurrence_on)) {
                return;
            }

            $latest = CarbonImmutable::parse($latest)->startOfDay();
            $recurring->forceFill([
                'next_occurrence_on' => $recurring->schedule()->after($latest),
                'last_occurrence_on' => $latest,
            ])->save();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Business $business, RecurringInvoice $recurring, RecurringInvoiceStatus $to, array $attributes, string $message): RecurringInvoice
    {
        return DB::transaction(function () use ($business, $recurring, $to, $attributes, $message) {
            $recurring = $this->locked($business, $recurring);

            if (! $recurring->status->canTransitionTo($to)) {
                throw new RecurringInvoiceException($message);
            }

            $recurring->forceFill(['status' => $to] + $attributes)->save();

            return $recurring;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Re-read the recurring invoice through its business with a row lock. Another
     * business's record is not found, whatever the caller checked.
     */
    private function locked(Business $business, RecurringInvoice $recurring): RecurringInvoice
    {
        return $business->recurringInvoices()->whereKey($recurring->getKey())->lockForUpdate()->firstOrFail();
    }
}
