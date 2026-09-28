<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkInvoicePaidRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * InvoicePolicy::markPaid runs as controller middleware before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The payment date cannot be before the invoice was issued, or in the future
     * (in the application's Asia/Kuala_Lumpur timezone).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paid_at' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$this->route('invoice')->issue_date->toDateString(),
                'before_or_equal:'.today()->toDateString(),
            ],
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
            'paid_at.after_or_equal' => 'The payment date cannot be before the issue date.',
            'paid_at.before_or_equal' => 'The payment date cannot be in the future.',
        ];
    }
}
