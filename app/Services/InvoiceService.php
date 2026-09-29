<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceStateException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place invoices and their lines are written.
 *
 * Totals are always calculated here from the lines; any totals, numbers,
 * statuses or positions a client sends are never used.
 */
class InvoiceService
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceNumberGenerator $numbers,
    ) {}

    /**
     * Create or update a draft from validated input.
     *
     * @param  array<string, mixed>  $data  Validated InvoiceRequest data.
     */
    public function saveDraft(User $user, array $data, ?Invoice $invoice = null): Invoice
    {
        return DB::transaction(function () use ($user, $data, $invoice) {
            if ($invoice === null) {
                $invoice = $user->invoices()->make();
                $invoice->forceFill(['status' => InvoiceStatus::Draft]);
            } else {
                $invoice = $this->lockedFresh($invoice);
                $this->ensureStatus($invoice, InvoiceStatus::Draft, 'Only draft invoices can be edited.');
            }

            $customer = $user->customers()->findOrFail($data['customer_id']);
            $lines = $this->resolveLines($user, $data['items'], $invoice->exists ? $invoice : null);
            $totals = $this->calculator->calculate($lines, $data['discount_amount'] ?? '0', $data['tax_rate'] ?? '0');

            $invoice->fill([
                'customer_id' => $customer->id,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'tax_label' => $data['tax_label'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? '0',
                'notes' => $data['notes'] ?? null,
            ]);
            $this->copyCustomer($invoice, $customer);
            $this->applyTotals($invoice, $totals);
            $invoice->forceFill(['currency_code' => config('bizflow.currency.code')]);
            $invoice->save();

            $invoice->items()->reorder()->delete();

            foreach ($lines as $index => $line) {
                $item = $invoice->items()->make($line);
                $item->forceFill([
                    'line_total' => $totals->lineTotals[$index],
                    'position' => $index + 1,
                ]);
                $item->save();
            }

            return $invoice->load('items');
        });
    }

    /**
     * Issue a draft: copy the customer's latest details, recalculate, number it and freeze it.
     */
    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $this->lockedFresh($invoice);

            // Issuing needs a draft specifically: paid → issued is also a valid transition
            // (mark unpaid), so the transition check alone would let a stale request re-issue
            // an invoice that was issued and paid after it was loaded.
            $this->ensureStatus($invoice, InvoiceStatus::Draft, 'Only draft invoices can be issued.');
            $this->ensureTransition($invoice, InvoiceStatus::Issued, 'Only draft invoices can be issued.');

            $items = $invoice->items()->get();

            if ($items->isEmpty()) {
                throw new InvoiceStateException('An invoice needs at least one line before it can be issued.');
            }

            $totals = $this->calculator->calculate(
                $items->map(fn ($item) => ['quantity' => $item->quantity, 'unit_price' => $item->unit_price])->all(),
                $invoice->discount_amount,
                $invoice->tax_rate,
            );

            $this->copyCustomer($invoice, $invoice->customer);
            $this->applyTotals($invoice, $totals);
            $this->numbers->assign($invoice);
            $invoice->forceFill([
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * Record that an issued invoice has been paid in full on the given date.
     */
    public function markPaid(Invoice $invoice, string $paidAt): Invoice
    {
        return $this->transition($invoice, InvoiceStatus::Paid, ['paid_at' => $paidAt],
            'Only issued invoices can be marked as paid.');
    }

    /**
     * Undo a "paid" mark, returning the invoice to issued.
     */
    public function markUnpaid(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, InvoiceStatus::Issued, ['paid_at' => null],
            'Only paid invoices can be marked as unpaid.', from: InvoiceStatus::Paid);
    }

    /**
     * Cancel an issued invoice. It keeps its number and all of its data.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, InvoiceStatus::Cancelled, ['cancelled_at' => now()],
            'Only issued invoices can be cancelled.');
    }

    /**
     * Delete a draft and its lines. Issued invoices are never deleted.
     */
    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = $this->lockedFresh($invoice);
            $this->ensureStatus($invoice, InvoiceStatus::Draft, 'Only draft invoices can be deleted.');
            $invoice->delete();
        });
    }

    /**
     * Turn validated line input into complete line attributes.
     *
     * A selected product fills any blank name, description, unit and price;
     * values the user typed are kept. Products are only ever loaded through the
     * user's own catalogue, and cost_price is never copied.
     *
     * Inactive products are refused unless the line keeps a product already on this draft.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{product_id: int|null, name: string, description: string|null, unit: string|null, quantity: string, unit_price: string}>
     */
    public function resolveLines(User $user, array $items, ?Invoice $invoice = null): array
    {
        // Keep the submitted order: validated() can rebuild the array in a different key order.
        ksort($items);

        $products = $this->ownedProducts($user, $items);
        $existingProductIds = $invoice?->items()->pluck('product_id')->filter()->all() ?? [];

        return array_values(array_map(function (array $item) use ($products, $existingProductIds) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $product = $productId === null ? null : $products->get($productId);

            if ($productId !== null && $product === null) {
                throw new InvalidArgumentException('Invoice lines may only use the user\'s own products.');
            }

            if ($product !== null && ! $product->is_active && ! in_array($product->id, $existingProductIds, true)) {
                throw new InvalidArgumentException('Inactive products cannot be added to an invoice.');
            }

            return [
                'product_id' => $product?->id,
                'name' => $this->filled($item['name'] ?? null) ?? $product?->name,
                'description' => $this->filled($item['description'] ?? null) ?? $product?->description,
                'unit' => $this->filled($item['unit'] ?? null) ?? $product?->unit,
                'quantity' => (string) $item['quantity'],
                'unit_price' => $this->filled($item['unit_price'] ?? null) ?? $product?->selling_price,
            ];
        }, $items));
    }

    /**
     * Load the products referenced by the lines, scoped to the user's catalogue.
     *
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, Product>
     */
    public function ownedProducts(User $user, array $items): Collection
    {
        $ids = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return $user->products()->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * Copy the customer's current billing details onto the invoice.
     */
    private function copyCustomer(Invoice $invoice, Customer $customer): void
    {
        $invoice->forceFill([
            'customer_name' => $customer->name,
            'customer_company_name' => $customer->company_name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'customer_address_line_1' => $customer->address_line_1,
            'customer_address_line_2' => $customer->address_line_2,
            'customer_city' => $customer->city,
            'customer_state' => $customer->state,
            'customer_postcode' => $customer->postcode,
            'customer_country' => $customer->country,
        ]);
    }

    private function applyTotals(Invoice $invoice, InvoiceTotals $totals): void
    {
        $invoice->forceFill([
            'subtotal' => $totals->subtotal,
            'discount_amount' => $totals->discountAmount,
            'tax_amount' => $totals->taxAmount,
            'total' => $totals->total,
        ]);
    }

    /**
     * Apply a status change allowed by InvoiceStatus::canTransitionTo().
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Invoice $invoice, InvoiceStatus $to, array $attributes, string $message, ?InvoiceStatus $from = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $to, $attributes, $message, $from) {
            $invoice = $this->lockedFresh($invoice);

            if ($from !== null) {
                $this->ensureStatus($invoice, $from, $message);
            }

            $this->ensureTransition($invoice, $to, $message);

            $invoice->forceFill(['status' => $to] + $attributes)->save();

            return $invoice;
        });
    }

    /**
     * Re-read the invoice with a row lock so concurrent requests cannot both change it.
     */
    private function lockedFresh(Invoice $invoice): Invoice
    {
        return Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
    }

    private function ensureStatus(Invoice $invoice, InvoiceStatus $status, string $message): void
    {
        if ($invoice->status !== $status) {
            throw new InvoiceStateException($message);
        }
    }

    private function ensureTransition(Invoice $invoice, InvoiceStatus $to, string $message): void
    {
        if (! $invoice->status->canTransitionTo($to)) {
            throw new InvoiceStateException($message);
        }
    }

    /**
     * Treat blank input as "not provided" so a product's value can fill it.
     */
    private function filled(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
