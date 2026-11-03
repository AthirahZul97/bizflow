<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The values the user confirms for a receipt. They are validated exactly like a hand-entered
 * expense: the rules and messages are ExpenseRequest's own, so the two can never drift apart.
 * Neither business_id nor created_by is accepted.
 */
class ConfirmExpenseReceiptRequest extends FormRequest
{
    /**
     * Ownership and write access are enforced by ExpenseReceiptPolicy through controller
     * middleware, which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return (new ExpenseRequest)->rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new ExpenseRequest)->messages();
    }
}
