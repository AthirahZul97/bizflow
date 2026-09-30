<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by ExpensePolicy through controller middleware,
     * which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The limits match the expenses columns exactly. There are deliberately no
     * business_id or created_by rules, so submitted values never reach validated() data.
     * "today" follows the application's Asia/Kuala_Lumpur timezone.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.Expense::MAX_AMOUNT],
            'payee' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
            'expense_date.before_or_equal' => 'The expense date cannot be in the future.',
            'expense_date.after_or_equal' => 'The expense date cannot be before 1 January 2000.',
            'amount.min' => 'The amount must be at least 0.01.',
            'amount.decimal' => 'The amount must be a plain number with at most 2 decimal places, e.g. 1500.00.',
        ];
    }
}
