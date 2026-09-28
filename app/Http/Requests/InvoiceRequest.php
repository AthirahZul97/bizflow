<?php

namespace App\Http\Requests;

use App\Exceptions\InvoiceCalculationException;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvoiceRequest extends FormRequest
{
    /**
     * Maximum number of lines on one invoice.
     */
    public const MAX_LINES = 100;

    /**
     * The fields that make up one line. A row where all of these are blank is ignored.
     */
    private const LINE_FIELDS = ['product_id', 'name', 'description', 'unit', 'quantity', 'unit_price'];

    /**
     * @var Collection<int, Product>|null
     */
    private ?Collection $products = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership and draft status are enforced by InvoicePolicy through
     * controller middleware, which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Status, numbering, currency, totals, positions, copied customer details and
     * user_id have no rules, so they never reach validated() data.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = fn (string $max) => ['numeric', 'decimal:0,2', 'min:0', 'max:'.$max];

        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('user_id', $this->user()->id),
            ],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'discount_amount' => ['nullable', ...$money(InvoiceCalculator::MAX_AMOUNT)],
            'tax_label' => ['nullable', 'string', 'max:30'],
            'tax_rate' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.product_id' => ['bail', 'nullable', 'integer', $this->productRule()],
            'items.*.name' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', ...$money('9999999999.99')],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one line to the invoice.',
            'items.min' => 'Add at least one line to the invoice.',
            'items.max' => 'An invoice can have at most '.self::MAX_LINES.' lines.',
            'items.*.name.required_without' => 'Enter a name for this line, or choose a product.',
            'items.*.unit_price.required_without' => 'Enter a price for this line, or choose a product.',
            'items.*.quantity.required' => 'Enter a quantity.',
            'items.*.quantity.min' => 'The quantity must be at least 0.01.',
            'items.*.quantity.decimal' => 'The quantity may have at most 2 decimal places.',
            'items.*.unit_price.decimal' => 'The price must be a plain number with at most 2 decimal places, e.g. 1500.00.',
            'discount_amount.decimal' => 'The discount must be a plain number with at most 2 decimal places.',
            'due_date.after_or_equal' => 'The due date cannot be before the issue date.',
        ];
    }

    /**
     * Once the individual fields are valid, calculate the invoice on the server
     * to reject a discount above the subtotal or totals too large to store.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data = $validator->validated();
                $service = app(InvoiceService::class);

                try {
                    app(InvoiceCalculator::class)->calculate(
                        $service->resolveLines($this->user(), $data['items'], $this->invoice()),
                        $data['discount_amount'] ?? '0',
                        $data['tax_rate'] ?? '0',
                    );
                } catch (InvoiceCalculationException $e) {
                    $validator->errors()->add($e->field, $e->getMessage());
                }
            },
        ];
    }

    /**
     * Drop completely blank rows so the form can include spare empty lines.
     */
    protected function prepareForValidation(): void
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
     * A product must be in the user's own catalogue, and active unless this
     * draft already uses it.
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
            ->ownedProducts($this->user(), array_filter((array) $this->input('items'), 'is_array'));
    }

    private function draftUsesProduct(Product $product): bool
    {
        return $this->invoice()?->items()->where('product_id', $product->id)->exists() ?? false;
    }

    /**
     * The draft being updated, or null when creating.
     */
    private function invoice(): ?Invoice
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice ? $invoice : null;
    }
}
