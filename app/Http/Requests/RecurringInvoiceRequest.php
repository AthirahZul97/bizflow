<?php

namespace App\Http\Requests;

use App\Enums\RecurringFrequency;
use App\Http\Requests\Concerns\ValidatesInvoiceLines;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringInvoiceRequest extends FormRequest
{
    use ValidatesInvoiceLines;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership and state are enforced by RecurringInvoicePolicy through
     * controller middleware, which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The lines, discount, tax and notes use exactly the invoice rules. Status,
     * the occurrence pointers, business_id and created_by have no rules, so they
     * never reach validated() data.
     *
     * The start date cannot be before today (Asia/Kuala_Lumpur), so a new schedule
     * never back-fills invoices. Once an invoice has been generated the start date
     * and frequency are fixed: they are not validated and the service ignores them.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $recurring = $this->recurringInvoice();
        $scheduleLocked = $recurring?->hasGenerated() ?? false;

        return [
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('business_id', $this->business()->getKey()),
            ],
            'frequency' => [Rule::excludeIf($scheduleLocked), 'required', Rule::enum(RecurringFrequency::class)],
            'start_date' => [Rule::excludeIf($scheduleLocked), 'required', 'date_format:Y-m-d', $this->startDateRule()],
            'end_date' => ['nullable', 'date_format:Y-m-d', $this->endDateRule()],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
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
            'items.required' => 'Add at least one line to the recurring invoice.',
            'items.min' => 'Add at least one line to the recurring invoice.',
        ];
    }

    /**
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
     * A recurring invoice has no draft invoice: inactive products are never accepted.
     */
    protected function lineDraft(): ?Invoice
    {
        return null;
    }

    /**
     * Today or later; an unchanged start date stays valid while nothing has been
     * generated (so a schedule waiting on a fix can still be edited).
     */
    private function startDateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $unchanged = $this->recurringInvoice()?->start_date->toDateString() === $value;

            if (! $unchanged && $value < today()->toDateString()) {
                $fail('The start date cannot be before today.');
            }
        };
    }

    /**
     * The end date is inclusive, so it cannot be before the first occurrence or
     * before an invoice that has already been generated.
     */
    private function endDateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $recurring = $this->recurringInvoice();

            if ($recurring?->hasGenerated()) {
                if ($value < $recurring->last_occurrence_on->toDateString()) {
                    $fail('The end date cannot be before the last invoice already generated ('.$recurring->last_occurrence_on->format('d M Y').').');
                }

                return;
            }

            $start = $this->input('start_date');

            if (is_string($start) && $value < $start) {
                $fail('The end date cannot be before the start date.');
            }
        };
    }

    /**
     * The recurring invoice being updated, or null when creating.
     */
    private function recurringInvoice(): ?RecurringInvoice
    {
        $recurring = $this->route('recurring_invoice');

        return $recurring instanceof RecurringInvoice ? $recurring : null;
    }
}
