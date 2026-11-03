<?php

namespace App\Http\Requests;

use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the owner of the current business may manage billing. Runs before validation, so
     * a non-owner never learns anything about plans.
     */
    public function authorize(): Response
    {
        return Gate::inspect('manageBilling', app(CurrentBusiness::class)->get());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Plans are shared platform catalogue data, not business data, so they are not scoped by
     * business; only an active plan can be chosen. Whether the caller may actually switch to it
     * (never a paid plan, Trial or Legacy) is decided by SubscriptionService, never here.
     * There is deliberately no business_id: the business is always the current one.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ];
    }
}
