@extends('layouts.app')

@section('title', 'Plans')

@php
    use App\Support\Money;

    $contact = config('billing.contact_email');
@endphp

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <a href="{{ route('billing.show') }}">&larr; Back to billing</a>
            <h1 class="h3 mt-1 mb-0">Plans</h1>
        </div>
    </div>

    <p class="text-body-secondary">
        Paid plans can't be bought online yet: contact us and we'll set your plan up. Switching to a free plan
        is instant, or, if you're in a paid period, when that period ends. Nothing you've created is ever deleted.
    </p>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0" id="plan-comparison">
                <thead>
                    <tr>
                        <th scope="col" class="text-body-secondary fw-normal"></th>
                        @foreach ($plans as $plan)
                            <th scope="col" class="text-center" data-plan="{{ $plan->code }}">
                                <div>{{ $plan->name }}</div>
                                <div class="fw-normal small text-body-secondary">
                                    @if ($plan->isFree())
                                        @if ($plan->isLegacy()) Grandfathered @else Free @endif
                                    @else
                                        {{ Money::format($plan->price) }}@if ($plan->billing_interval) / {{ $plan->billing_interval->value }}@endif
                                    @endif
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($catalogue as $entitlement)
                        <tr>
                            <th scope="row" class="fw-normal">{{ $entitlement->label() }}</th>
                            @foreach ($plans as $plan)
                                @php($value = $plan->valueOf($entitlement))
                                <td class="text-center">
                                    @if ($entitlement->isFlag())
                                        {{ $value ? 'Included' : '—' }}
                                    @elseif ($value === null)
                                        Unlimited
                                    @elseif ($value === 0)
                                        —
                                    @else
                                        Up to {{ number_format($value) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        <th scope="row" class="fw-normal"></th>
                        @foreach ($plans as $plan)
                            @php($action = $actions[$plan->code])
                            <td class="text-center">
                                @if ($action === 'current')
                                    <span class="badge text-bg-primary">Your plan</span>
                                @elseif ($action === 'scheduled')
                                    <span class="badge text-bg-info">Starts when your period ends</span>
                                @elseif ($action === 'switch' && $canManage)
                                    <form method="POST" action="{{ route('billing.change') }}">
                                        @csrf
                                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Switch to {{ $plan->name }}</button>
                                    </form>
                                @elseif ($action === 'switch')
                                    <span class="small text-body-secondary">Only the owner can change the plan</span>
                                @else
                                    <span class="small">
                                        @if ($contact)
                                            <a href="mailto:{{ $contact }}">Contact us to upgrade</a>
                                        @else
                                            Contact us to upgrade
                                        @endif
                                    </span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if (config('app.env') !== 'production')
        <p class="small text-body-secondary mt-3">Plan limits and prices shown here are placeholders for development, not final commercial terms.</p>
    @endif
@endsection
