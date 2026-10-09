<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseReceiptRequest extends FormRequest
{
    /**
     * Ownership and the plan's OCR allowance are enforced by ExpenseReceiptPolicy through
     * controller middleware, which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only that a file arrived. Whether it is an acceptable receipt (type, size, content) is
     * decided from the file's bytes by ReceiptFileInspector, never from anything the client
     * says about it. There is deliberately no business_id rule: ownership never comes from input.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'receipt' => ['required', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipt.required' => 'Choose a receipt to upload.',
            'receipt.file' => 'The file could not be uploaded. It may be larger than the server allows.',
        ];
    }
}
