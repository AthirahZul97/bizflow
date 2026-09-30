<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Services\InvoiceEmailService;
use App\Support\CurrentBusiness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendInvoiceEmailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership and status are enforced by InvoicePolicy::sendEmail through
     * controller middleware, which runs before this request is validated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Only the choice of address is accepted: the address copied onto the invoice
     * or the customer's current one. There is no free-text address, message,
     * subject or business/invoice field; anything else submitted is ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', Rule::in([InvoiceEmail::SOURCE_INVOICE, InvoiceEmail::SOURCE_CUSTOMER])],
        ];
    }

    /**
     * The chosen address must exist and be a valid address.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Invoice $invoice */
                $invoice = $this->route('invoice');
                $options = app(InvoiceEmailService::class)->recipientOptions(app(CurrentBusiness::class)->get(), $invoice);
                $address = $options[$this->input('recipient')] ?? null;

                if ($address === null) {
                    $validator->errors()->add('recipient', 'There is no email address for this choice. Add one to the customer first.');
                } elseif (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $validator->errors()->add('recipient', "“{$address}” is not a valid email address. Correct it on the customer first.");
                }
            },
        ];
    }
}
