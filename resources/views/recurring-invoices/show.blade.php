@extends('layouts.app')

@section('title', $recurring->name)

@php
    use App\Support\Money;

    $dueCount = $recurring->dueCount($today);
@endphp

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <a href="{{ route('recurring-invoices.index') }}">&larr; Back to recurring invoices</a>

        <div class="d-flex flex-wrap gap-2">
            @unless ($recurring->isCancelled())
                <a href="{{ route('recurring-invoices.edit', $recurring) }}" class="btn btn-outline-primary">Edit</a>
            @endunless

            @if ($recurring->isActive())
                <form method="POST" action="{{ route('recurring-invoices.pause', $recurring) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">Pause</button>
                </form>
            @endif

            @if ($recurring->isPaused())
                <form method="POST" action="{{ route('recurring-invoices.resume', $recurring) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-success">Resume</button>
                </form>
            @endif

            @unless ($recurring->isCancelled())
                <a href="{{ route('recurring-invoices.cancel.confirm', $recurring) }}" class="btn btn-outline-danger">Cancel</a>
            @endunless

            @unless ($recurring->hasGenerated())
                <a href="{{ route('recurring-invoices.delete', $recurring) }}" class="btn btn-outline-danger">Delete</a>
            @endunless
        </div>
    </div>

    @if ($recurring->last_generation_error && ! $recurring->isCancelled())
        <div class="alert alert-danger" role="alert" data-generation-error>
            <div class="fw-semibold">The last invoice could not be generated</div>
            <div>{{ $recurring->last_generation_error }}</div>
            <div class="small mt-1">
                Tried {{ $recurring->last_generation_failed_at?->format('d M Y, g:i A') }}.
                Nothing was created; it is tried again automatically every hour.
            </div>
        </div>
    @endif

    @if ($dueCount > 0)
        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="status" data-due-notice>
            <div>
                <span class="fw-semibold">{{ $dueCount }} {{ $dueCount === 1 ? 'invoice is' : 'invoices are' }} due</span>
                (from {{ $recurring->next_occurrence_on->format('d M Y') }}).
                {{ $dueCount === 1 ? 'It is' : 'They are' }} generated automatically, oldest first, within the hour.
            </div>
            <form method="POST" action="{{ route('recurring-invoices.generate', $recurring) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-warning" data-generate-now>
                    Generate now ({{ $recurring->next_occurrence_on->format('d M Y') }})
                </button>
            </form>
        </div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                <div>
                    <h1 class="h3 mb-1">{{ $recurring->name }}</h1>
                    <div>@include('recurring-invoices._status-badge')</div>
                </div>
                <div class="text-md-end">
                    <div class="small text-body-secondary text-uppercase">Each invoice</div>
                    <div class="fs-4 fw-semibold">{{ $totals ? Money::format($totals->total) : '—' }}</div>
                </div>
            </div>

            <dl class="row mb-0">
                <dt class="col-sm-4 col-lg-3 fw-normal text-body-secondary">Customer</dt>
                <dd class="col-sm-8 col-lg-9">
                    <a href="{{ route('customers.show', $recurring->customer) }}">{{ $recurring->customer->name }}</a>
                    @if ($recurring->customer->company_name)
                        <span class="text-body-secondary">&mdash; {{ $recurring->customer->company_name }}</span>
                    @endif
                </dd>

                <dt class="col-sm-4 col-lg-3 fw-normal text-body-secondary">Schedule</dt>
                <dd class="col-sm-8 col-lg-9" data-schedule>
                    {{ $recurring->frequency->label() }}, from {{ $recurring->start_date->format('d M Y') }}
                    @if ($recurring->end_date)
                        until {{ $recurring->end_date->format('d M Y') }} (inclusive)
                    @else
                        with no end date
                    @endif
                </dd>

                <dt class="col-sm-4 col-lg-3 fw-normal text-body-secondary">Next invoice</dt>
                <dd class="col-sm-8 col-lg-9" data-next>@include('recurring-invoices._next')</dd>

                <dt class="col-sm-4 col-lg-3 fw-normal text-body-secondary">Payment terms</dt>
                <dd class="col-sm-8 col-lg-9">Due {{ $recurring->payment_terms_days }} {{ $recurring->payment_terms_days === 1 ? 'day' : 'days' }} after the invoice date</dd>

                <dt class="col-sm-4 col-lg-3 fw-normal text-body-secondary">Last generated</dt>
                <dd class="col-sm-8 col-lg-9 mb-0">
                    @if ($recurring->last_occurrence_on)
                        Invoice for {{ $recurring->last_occurrence_on->format('d M Y') }}
                    @else
                        Nothing yet
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body p-4">
            <h2 class="h6 text-body-secondary text-uppercase mb-3">Invoice template</h2>

            <div class="table-responsive mb-3">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-end">Qty</th>
                            <th scope="col" class="text-end">Unit price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recurring->items as $item)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->name }}</div>
                                    @if ($item->description)
                                        <div class="small text-body-secondary">{!! nl2br(e($item->description)) !!}</div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">{{ $item->quantity }}@if ($item->unit) {{ $item->unit }}@endif</td>
                                <td class="text-end text-nowrap">{{ $item->money('unit_price') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($totals)
                <div class="row justify-content-end">
                    <div class="col-md-6 col-lg-5">
                        <dl class="row mb-0">
                            <dt class="col-7 fw-normal">Subtotal</dt>
                            <dd class="col-5 text-end mb-1">{{ Money::format($totals->subtotal) }}</dd>

                            @if ($recurring->discount_amount !== '0.00')
                                <dt class="col-7 fw-normal">Discount</dt>
                                <dd class="col-5 text-end mb-1">&minus; {{ Money::format($totals->discountAmount) }}</dd>
                            @endif

                            @if ($recurring->tax_rate !== '0.00')
                                <dt class="col-7 fw-normal">{{ $recurring->tax_label ?: 'Tax' }} ({{ $recurring->tax_rate }}%)</dt>
                                <dd class="col-5 text-end mb-1">{{ Money::format($totals->taxAmount) }}</dd>
                            @endif

                            <dt class="col-7 border-top pt-2 fs-5">Total</dt>
                            <dd class="col-5 border-top pt-2 fs-5 fw-semibold text-end mb-0">{{ Money::format($totals->total) }}</dd>
                        </dl>
                    </div>
                </div>
            @endif

            @if ($recurring->notes)
                <div class="mt-3">
                    <div class="small text-body-secondary text-uppercase">Notes</div>
                    <div>{!! nl2br(e($recurring->notes)) !!}</div>
                </div>
            @endif

            <p class="form-text mb-0 mt-3">
                Each invoice is created as a draft with the customer’s details at that time. Editing this template
                only affects invoices generated afterwards.
            </p>
        </div>
    </div>

    <div class="card shadow-sm" data-generated-invoices>
        <div class="card-body p-4">
            <h2 class="h6 text-body-secondary text-uppercase mb-3">Generated invoices</h2>

            @if ($invoices->isEmpty())
                <p class="mb-0 text-body-secondary">
                    @if ($recurring->hasGenerated())
                        The invoices generated so far have been deleted.
                    @else
                        No invoices have been generated yet.
                    @endif
                </p>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($invoices as $invoice)
                        <li class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number ?? 'Draft invoice' }}</a>
                                <span class="text-body-secondary">for {{ $invoice->recurring_occurrence_on->format('d M Y') }}</span>
                                @include('invoices._status-badge')
                            </div>
                            <div class="text-nowrap">{{ $invoice->money('total') }}</div>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3">
                    {{ $invoices->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
