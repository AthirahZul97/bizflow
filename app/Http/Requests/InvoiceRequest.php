<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesInvoiceLines;
use App\Models\Invoice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    use ValidatesInvoiceLines;

    /**
     * Maximum number of lines on one invoice.
     */
    public const MAX_LINES = 100;

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
     * Status, numbering, currency, totals, positions, copied customer details,
     * business_id and created_by have no rules, so they never reach validated()
     * data. The customer and products must belong to the current business.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('business_id', $this->business()->getKey()),
            ],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            ...$this->lineRules(),
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
            ...$this->lineMessages(),
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
        return [$this->calculationCheck()];
    }

    /**
     * Drop completely blank rows so the form can include spare empty lines.
     */
    protected function prepareForValidation(): void
    {
        $this->dropBlankLines();
    }

    /**
     * The draft being updated, or null when creating.
     */
    protected function lineDraft(): ?Invoice
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice ? $invoice : null;
    }
}
