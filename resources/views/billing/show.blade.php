@extends('layouts.app')

@section('title', 'Billing')

@php
    use App\Enums\AccessState;
    use App\Support\Money;

    $plan = $entitlements->plan();
    $resolved = $entitlements->resolved();
    $access = $entitlements->access();
    $badge = match ($access) {
        AccessState::Full => 'success',
        AccessState::Grace => 'warning',
        AccessState::ReadOnly => 'danger',
    };
@endphp

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Billing</h1>
        <a href="{{ route('billing.plans') }}" class="btn btn-outline-primary">Compare plans</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-body-secondary text-uppercase mb-3">Current plan</h2>

                    @if ($plan)
                        <p class="h4 mb-1">{{ $plan->name }}</p>
                        <p class="mb-3">
                            <span class="badge text-bg-{{ $badge }}" id="access-state">{{ $access->label() }}</span>
                            @if ($plan->isFree())
                                <span class="text-body-secondary small ms-1">No charge</span>
                            @else
                                <span class="text-body-secondary small ms-1">{{ Money::format($plan->price) }}@if ($plan->billing_interval) / {{ $plan->billing_interval->value }}@endif</span>
                            @endif
                        </p>
                    @else
                        <p class="h5 mb-3"><span class="badge text-bg-danger" id="access-state">{{ $access->label() }}</span> No subscription</p>
                    @endif

                    <dl class="row small mb-0">
                        @if ($resolved->reason === 'trial' && $resolved->endsAt)
                            <dt class="col-5">Trial ends</dt>
                            <dd class="col-7">{{ $resolved->endsAt->format('d M Y, H:i') }}</dd>
                        @elseif ($resolved->reason === 'trial_ended' && $resolved->endsAt)
                            <dt class="col-5">Trial ended</dt>
                            <dd class="col-7">{{ $resolved->endsAt->format('d M Y') }}</dd>
                        @elseif ($subscription?->current_period_ends_at)
                            <dt class="col-5">{{ $subscription->cancel_at_period_end ? 'Ends' : 'Renews / ends' }}</dt>
                            <dd class="col-7">{{ $subscription->current_period_ends_at->format('d M Y') }}</dd>
                        @elseif ($plan && $access !== AccessState::ReadOnly)
                            <dt class="col-5">Period</dt>
                            <dd class="col-7">No end date</dd>
                        @endif

                        @if ($access === AccessState::Grace && $resolved->graceEndsAt)
                            <dt class="col-5">Grace ends</dt>
                            <dd class="col-7">{{ $resolved->graceEndsAt->format('d M Y') }}</dd>
                        @endif

                        @if ($subscription?->cancel_at_period_end)
                            <dt class="col-5">Cancellation</dt>
                            <dd class="col-7" id="cancellation-state">Set to cancel at the end of the period</dd>
                        @endif

                        @if ($subscription?->pending_plan_id && $subscription->pendingPlan)
                            <dt class="col-5">Next plan</dt>
                            <dd class="col-7" id="pending-plan">{{ $subscription->pendingPlan->name }}, from {{ $subscription->current_period_ends_at->format('d M Y') }}</dd>
                        @endif
                    </dl>

                    @if ($access === AccessState::ReadOnly)
                        <p class="small text-body-secondary mt-3 mb-0">
                            Your data is safe. You can still view and download everything, but nothing can be added or
                            changed until you choose a plan.
                        </p>
                    @endif

                    @if ($canManage)
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @if ($subscription && ($subscription->cancel_at_period_end || $subscription->pending_plan_id) && $subscription->current_period_ends_at?->isFuture())
                                <form method="POST" action="{{ route('billing.resume') }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-success">Keep my subscription</button>
                                </form>
                            @endif

                            @if ($subscription && ! $subscription->cancel_at_period_end && $subscription->trial_ends_at === null && $subscription->current_period_ends_at?->isFuture())
                                <a href="{{ route('billing.cancel.confirm') }}" class="btn btn-outline-danger">Cancel subscription</a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-body-secondary text-uppercase mb-3">Usage and limits</h2>

                    <ul class="list-unstyled mb-0" id="usage">
                        @foreach ($usage as $row)
                            @php($entitlement = $row['entitlement'])
                            <li class="mb-3" data-entitlement="{{ $entitlement->value }}">
                                <div class="d-flex justify-content-between small">
                                    <span>{{ $entitlement->label() }}</span>
                                    @if ($entitlement->isFlag())
                                        <span class="{{ $row['included'] ? 'text-success' : 'text-body-secondary' }}">{{ $row['included'] ? 'Included' : 'Not included' }}</span>
                                    @elseif (! $row['included'])
                                        <span class="text-body-secondary">Not included</span>
                                    @elseif ($row['limit'] === null)
                                        <span>{{ number_format($row['used']) }} used &middot; Unlimited</span>
                                    @else
                                        <span class="{{ $row['over'] ? 'text-danger fw-semibold' : '' }}">{{ number_format($row['used']) }} / {{ number_format($row['limit']) }}</span>
                                    @endif
                                </div>
                                @if ($entitlement->isLimit() && $row['limit'] !== null && $row['limit'] > 0)
                                    @php($percent = min(100, (int) round($row['used'] / $row['limit'] * 100)))
                                    <div class="progress mt-1" style="height: .4rem" role="progressbar" aria-label="{{ $entitlement->label() }} used"
                                         aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar @if ($row['over']) bg-danger @elseif ($percent >= 80) bg-warning @endif" style="width: {{ $percent }}%"></div>
                                    </div>
                                    @if ($row['over'])
                                        <div class="small text-danger mt-1">Over the limit. Your records are kept, but you can't add more until you are under it.</div>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="small text-body-secondary mb-0">Invoices are counted by the month they were issued (Malaysia time), including cancelled ones.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h6 text-body-secondary text-uppercase mb-3">Subscription history</h2>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="subscription-history">
                    <thead>
                        <tr>
                            <th scope="col">Plan</th>
                            <th scope="col">Status</th>
                            <th scope="col">Started</th>
                            <th scope="col">Ended</th>
                            <th scope="col">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $row)
                            <tr>
                                <td>{{ $row->plan->name }} <span class="text-body-secondary small">v{{ $row->plan->version }}</span></td>
                                <td>
                                    @if ($row->isCurrent())
                                        <span class="badge text-bg-primary">Current</span>
                                    @else
                                        <span class="badge text-bg-secondary">{{ $row->status->label() }}</span>
                                    @endif
                                </td>
                                <td>{{ $row->started_at->format('d M Y') }}</td>
                                <td>{{ $row->ended_at?->format('d M Y') ?? '—' }}</td>
                                <td>{{ $row->end_reason ? ucfirst($row->end_reason) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
