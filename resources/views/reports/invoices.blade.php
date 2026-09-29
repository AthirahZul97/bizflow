@extends('layouts.app')

@section('title', 'Invoice report')

@section('content')
    @include('reports._header', ['periodQuery' => $invoiceQuery])

    @php
        $money = fn (string $amount) => \App\Support\Money::format($amount);
        $views = [
            'invoiced' => 'Issued in period',
            'received' => 'Paid in period',
            'outstanding' => 'Outstanding now',
        ];
        $statuses = [null => 'All', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid'];
    @endphp

    <div class="d-flex flex-wrap gap-2 mb-3 d-print-none">
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Invoice view">
            @foreach ($views as $key => $label)
                <a href="{{ route('reports.invoices', $period->query() + ['view' => $key]) }}"
                   @class(['btn', 'btn-secondary' => $view === $key, 'btn-outline-secondary' => $view !== $key])
                   @if ($view === $key) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </div>

        @if ($view === 'invoiced')
            <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Status">
                @foreach ($statuses as $key => $label)
                    <a href="{{ route('reports.invoices', $period->query() + array_filter(['view' => 'invoiced', 'status' => $key])) }}"
                       @class(['btn', 'btn-primary' => $status === ($key ?: null), 'btn-outline-primary' => $status !== ($key ?: null)])
                       @if ($status === ($key ?: null)) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </div>

    <h2 class="h5 mb-1">{{ $views[$view] }}</h2>
    <p class="small text-body-secondary mb-3">
        @if ($view === 'invoiced')
            Issued and paid invoices with an issue date in {{ $period->label() }}.
        @elseif ($view === 'received')
            Invoices marked as paid with a payment date in {{ $period->label() }}.
        @else
            All unpaid issued invoices as of today ({{ today()->format('j M Y') }}), oldest due date first. Not affected by the period.
        @endif
    </p>

    @if ($ageing)
        <div class="row row-cols-2 row-cols-md-5 g-2 mb-3" data-report-ageing>
            @foreach ($ageing as $bucket)
                <div class="col">
                    <div @class(['card', 'h-100', 'border-danger' => $bucket['key'] === 'days_over_90' && $bucket['count'] > 0]) data-ageing="{{ $bucket['key'] }}">
                        <div class="card-body py-2">
                            <div class="small text-body-secondary">{{ $bucket['label'] }}</div>
                            <div class="fw-semibold">{{ $money($bucket['amount']) }}</div>
                            <div class="small text-body-secondary">{{ $bucket['count'] }} {{ Str::plural('invoice', $bucket['count']) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="mb-3" data-report-invoice-total>
                {{ $totals['count'] }} {{ Str::plural('invoice', $totals['count']) }} &middot; Total <strong>{{ $money($totals['amount']) }}</strong>
            </p>

            @if ($invoices->isEmpty())
                <p class="text-body-secondary mb-0" data-report-empty>No invoices match this view.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Invoice</th>
                                <th scope="col">Customer</th>
                                <th scope="col">Issued</th>
                                <th scope="col">Due</th>
                                <th scope="col">Paid</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoices as $invoice)
                                <tr data-invoice-row="{{ $invoice->id }}">
                                    <td class="text-nowrap"><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td>
                                    <td>
                                        {{ $invoice->customer_name }}
                                        @if ($invoice->customer_company_name)
                                            <div class="small text-body-secondary">{{ $invoice->customer_company_name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">{{ $invoice->issue_date->format('d M Y') }}</td>
                                    <td class="text-nowrap">{{ $invoice->due_date->format('d M Y') }}</td>
                                    <td class="text-nowrap">{{ $invoice->paid_at?->format('d M Y') ?? '—' }}</td>
                                    <td>@include('invoices._status-badge')</td>
                                    <td class="text-end text-nowrap">{{ $invoice->money('total') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 d-print-none">{{ $invoices->links() }}</div>
            @endif
        </div>
    </div>

    @include('reports._footnote')
@endsection
