@extends('layouts.app')

@section('title', $invoice->invoice_number ?? 'Draft invoice')

@section('content')
    {{-- Lifecycle actions --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 d-print-none">
        <a href="{{ route('invoices.index') }}">&larr; Back to invoices</a>

        <div class="d-flex flex-wrap gap-2">
            @if ($invoice->status->isDraft())
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-outline-primary">Edit</a>
                <a href="{{ route('invoices.delete', $invoice) }}" class="btn btn-outline-danger">Delete</a>
                <form method="POST" action="{{ route('invoices.issue', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Issue invoice</button>
                </form>
            @endif

            @if ($invoice->status->isIssued())
                <a href="{{ route('invoices.cancel.confirm', $invoice) }}" class="btn btn-outline-danger">Cancel invoice</a>
            @endif

            @if ($invoice->status->isPaid())
                <form method="POST" action="{{ route('invoices.mark-unpaid', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">Mark as unpaid</button>
                </form>
            @endif

            @unless ($invoice->status->isDraft())
                <a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-outline-secondary" data-download-pdf>Download PDF</a>
            @endunless

            <button type="button" class="btn btn-outline-secondary" data-print>Print</button>
        </div>
    </div>

    @if ($invoice->status->isIssued())
        <div class="card shadow-sm mb-3 d-print-none">
            <div class="card-body">
                <form method="POST" action="{{ route('invoices.mark-paid', $invoice) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-sm-auto">
                        <label for="paid_at" class="form-label">Payment date</label>
                        <input id="paid_at" type="date" name="paid_at" value="{{ old('paid_at', today()->toDateString()) }}"
                               min="{{ $invoice->issue_date->toDateString() }}" max="{{ today()->toDateString() }}"
                               class="form-control @error('paid_at') is-invalid @enderror" required>
                        @error('paid_at')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-success">Mark as paid</button>
                    </div>
                    <div class="col-12 form-text">Records that the invoice was paid in full on this date.</div>
                </form>
            </div>
        </div>
    @endif

    {{-- The invoice document --}}
    <div class="card shadow-sm">
        <div class="card-body p-4 p-lg-5">
            <div class="d-flex flex-wrap justify-content-between gap-3 mb-4">
                <div>
                    <div class="fs-4 fw-semibold">{{ $invoice->user->name }}</div>
                </div>
                <div class="text-md-end">
                    <h1 class="h3 mb-1">{{ $invoice->invoice_number ? 'Invoice '.$invoice->invoice_number : 'Draft invoice' }}</h1>
                    <div class="mb-2">@include('invoices._status-badge')</div>
                    <div class="small">Issue date: {{ $invoice->issue_date->format('d M Y') }}</div>
                    <div class="small">Due date: {{ $invoice->due_date->format('d M Y') }}</div>
                    @if ($invoice->paid_at)
                        <div class="small text-success">Marked as paid on {{ $invoice->paid_at->format('d M Y') }}</div>
                    @endif
                    @if ($invoice->cancelled_at)
                        <div class="small text-danger">Cancelled on {{ $invoice->cancelled_at->format('d M Y') }}</div>
                    @endif
                </div>
            </div>

            <div class="mb-4">
                <div class="small text-body-secondary text-uppercase">Bill to</div>
                <div class="fw-semibold">{{ $invoice->customer_name }}</div>
                @if ($invoice->customer_company_name)
                    <div>{{ $invoice->customer_company_name }}</div>
                @endif
                @foreach ($invoice->customerAddressLines() as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($invoice->customer_email)
                    <div>{{ $invoice->customer_email }}</div>
                @endif
                @if ($invoice->customer_phone)
                    <div>{{ $invoice->customer_phone }}</div>
                @endif
            </div>

            <div class="table-responsive mb-3">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Item</th>
                            <th scope="col" class="text-end">Qty</th>
                            <th scope="col" class="text-end">Unit price</th>
                            <th scope="col" class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $item->name }}</div>
                                    @if ($item->description)
                                        <div class="small text-body-secondary">{!! nl2br(e($item->description)) !!}</div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">{{ $item->quantity }}@if ($item->unit) {{ $item->unit }}@endif</td>
                                <td class="text-end text-nowrap">{{ $item->money('unit_price') }}</td>
                                <td class="text-end text-nowrap">{{ $item->money('line_total') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="row justify-content-end mb-4">
                <div class="col-md-6 col-lg-5">
                    <dl class="row mb-0">
                        <dt class="col-7 fw-normal">Subtotal</dt>
                        <dd class="col-5 text-end mb-1">{{ $invoice->money('subtotal') }}</dd>

                        @if ($invoice->discount_amount !== '0.00')
                            <dt class="col-7 fw-normal">Discount</dt>
                            <dd class="col-5 text-end mb-1">&minus; {{ $invoice->money('discount_amount') }}</dd>
                        @endif

                        @if ($invoice->tax_rate !== '0.00')
                            <dt class="col-7 fw-normal">{{ $invoice->tax_label ?: 'Tax' }} ({{ $invoice->tax_rate }}%)</dt>
                            <dd class="col-5 text-end mb-1">{{ $invoice->money('tax_amount') }}</dd>
                        @endif

                        <dt class="col-7 border-top pt-2 fs-5">Total ({{ $invoice->currency_code }})</dt>
                        <dd class="col-5 border-top pt-2 fs-5 fw-semibold text-end mb-0">{{ $invoice->money('total') }}</dd>
                    </dl>
                </div>
            </div>

            @if ($invoice->notes)
                <div>
                    <div class="small text-body-secondary text-uppercase">Notes</div>
                    <div>{!! nl2br(e($invoice->notes)) !!}</div>
                </div>
            @endif
        </div>
    </div>
@endsection
