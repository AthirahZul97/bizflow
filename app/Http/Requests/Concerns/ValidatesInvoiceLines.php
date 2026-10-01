<?php

namespace App\Http\Requests\Concerns;

use App\Exceptions\InvoiceCalculationException;
use App\Http\Requests\InvoiceRequest;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceService;
use App\Support\CurrentBusiness;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * The lines, discount, tax and notes rules shared by invoices and recurring
 * invoice templates, so both validate and calculate identically.
 */
trait ValidatesInvoiceLines
{
    /**
     * The fields that make up one line. A row where all of these are blank is ignored.
     */
    private const LINE_FIELDS = ['product_id', 'name', 'description', 'unit', 'quantity', 'unit_price'];

    /**
     * @var Collection<int, Product>|null
     */
    private ?Collection $products = null;

    /**
     * The draft invoice being edited, whose existing products stay usable even if
     * they have since been made inactive. Null when there is none.
     */
    abstract protected function lineDraft(): ?Invoice;

    /**
     * Rules for the discount, tax, notes and lines.
     *
     * @return array<string, mixed>
     */
    protected function lineRules(): array
    {
        $money = fn (string $max) => ['numeric', 'decimal:0,2', 'min:0', 'max:'.$max];

        return [
            'discount_amount' => ['nullable', ...$money(InvoiceCalculator::MAX_AMOUNT)],
            'tax_label' => ['nullable', 'string', 'max:30'],
            'tax_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1', 'max:'.InvoiceRequest::MAX_LINES],
            'items.*.product_id' => ['bail', 'nullable', 'integer', $this->productRule()],
            'items.*.name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', ...$money('9999999999.99')],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function lineMessages(): array
    {
        return [
            'items.required' => 'Add at least one line to the invoice.',
            'items.min' => 'Add at least one line to the invoice.',
            'items.max' => 'An invoice can have at most '.InvoiceRequest::MAX_LINES.' lines.',
            'items.*.name.required_without' => 'Enter a name for this line, or choose a product.',
            'items.*.unit_price.required_without' => 'Enter a price for this line, or choose a product.',
            'items.*.quantity.required' => 'Enter a quantity.',
            'items.*.quantity.min' => 'The quantity must be at least 0.01.',
            'items.*.quantity.decimal' => 'The quantity may have at most 2 decimal places.',
            'items.*.unit_price.decimal' => 'The price must be a plain number with at most 2 decimal places, e.g. 1500.00.',
            'discount_amount.decimal' => 'The discount must be a plain number with at most 2 decimal places.',
        ];
    }

    /**
     * Once the individual fields are valid, calculate on the server to reject a
     * discount above the subtotal or totals too large to store.
     */
    protected function calculationCheck(): Closure
    {
        return function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->validated();
            $service = app(InvoiceService::class);

            try {
                app(InvoiceCalculator::class)->calculate(
                    $service->resolveLines($this->business(), $data['items'], $this->lineDraft()),
                    $data['discount_amount'] ?? '0',
                    $data['tax_rate'] ?? '0',
                );
            } catch (InvoiceCalculationException $e) {
                $validator->errors()->add($e->field, $e->getMessage());
            }
        };
    }

    /**
     * Drop completely blank rows so the form can include spare empty lines.
     */
    protected function dropBlankLines(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $this->merge([
            'items' => array_values(array_filter($items, function ($row) {
                if (! is_array($row)) {
                    return true;
                }

                foreach (self::LINE_FIELDS as $field) {
                    if (($row[$field] ?? null) !== null && $row[$field] !== '') {
                        return true;
                    }
                }

                return false;
            })),
        ]);
    }

    /**
     * A product must be in the current business's catalogue, and active unless the
     * draft being edited already uses it.
     */
    private function productRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $product = $this->ownedProducts()->get((int) $value);

            if ($product === null) {
                $fail('The selected product is invalid.');

                return;
            }

            if (! $product->is_active && ! $this->draftUsesProduct($product)) {
                $fail('This product is inactive and cannot be added to an invoice.');
            }
        };
    }

    /**
     * @return Collection<int, Product>
     */
    private function ownedProducts(): Collection
    {
        return $this->products ??= app(InvoiceService::class)
            ->ownedProducts($this->business(), array_filter((array) $this->input('items'), 'is_array'));
    }

    private function business(): Business
    {
        return app(CurrentBusiness::class)->get();
    }

    private function draftUsesProduct(Product $product): bool
    {
        return $this->lineDraft()?->items()->where('product_id', $product->id)->exists() ?? false;
    }
}
