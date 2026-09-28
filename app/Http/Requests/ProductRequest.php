<?php

namespace App\Http\Requests;

use App\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Largest value a DECIMAL(12,2) column can hold.
     */
    private const MAX_PRICE = '9999999999.99';

    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is enforced by ProductPolicy through controller middleware,
     * which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * There is deliberately no user_id rule, so a submitted user_id never
     * reaches validated() data.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $price = ['numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PRICE];

        return [
            'type' => ['required', Rule::enum(ProductType::class)],
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[A-Z0-9._\/-]+$/',
                // Scoped to this user's items; ignore() excludes the item itself on update.
                Rule::unique('products', 'sku')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('product')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', ...$price],
            'cost_price' => ['nullable', ...$price],
            'is_active' => ['required', 'boolean'],
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
            'sku.regex' => 'The SKU may only contain letters, numbers and . _ / - characters.',
            'sku.unique' => 'You already have an item with this SKU.',
            'selling_price.decimal' => 'The selling price must be a plain number with at most 2 decimal places, e.g. 1500.00.',
            'cost_price.decimal' => 'The cost price must be a plain number with at most 2 decimal places, e.g. 1500.00.',
        ];
    }

    /**
     * Normalize the SKU so uniqueness behaves the same on MySQL and SQLite.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->sku)) {
            $sku = mb_strtoupper(trim($this->sku));

            $this->merge(['sku' => $sku === '' ? null : $sku]);
        }
    }
}
