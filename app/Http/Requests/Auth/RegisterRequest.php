<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordFitsBcrypt;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Passwords are limited to 72 characters and, because bcrypt only uses the first 72 BYTES, to 72
     * bytes (PasswordFitsBcrypt), so multibyte characters cannot slip past the limit.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', 'min:8', 'max:72', new PasswordFitsBcrypt],
        ];
    }

    /**
     * Normalize input before validation so email uniqueness behaves the same on every database.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'business_name' => is_string($this->business_name) ? trim($this->business_name) : $this->business_name,
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
        ]);
    }
}
