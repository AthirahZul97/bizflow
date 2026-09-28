@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Invoices</h1>
        <a href="{{ route('invoices.create') }}" class="btn btn-primary">New invoice</a>
    </div>

    <form method="GET" action="{{ route('invoices.index') }}" class="row g-2 mb-3" role="search">
        <div class="col-md-7">
            <input type="search" name="search" value="{{ $search }}" class="form-control"
                   placeholder="Search by invoice number, customer or company" aria-label="Search invoices" maxlength="100">
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select" aria-label="Filter by status">
                <option value="">All statuses</option>
                @foreach (\App\Models\Invoice::STATUS_FILTERS as $filter)
                    <option value="{{ $filter }}" @selected($status === $filter)>{{ ucfirst($filter) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-secondary flex-grow-1">Filter</button>
            @if ($filtered)
                <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($invoices->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                @if ($filtered)
                    <p class="mb-3">No invoices match your search or filter.</p>
                    <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary">Clear filters</a>
                @else
                    <p class="mb-3">You haven't created any invoices yet.</p>
                    <a href="{{ route('invoices.create') }}" class="btn btn-primary">Create your first invoice</a>
                @endif
            </div>
        </div>
    @else
        {{-- Table on medium screens and up --}}
        <div class="card shadow-sm d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Number</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Issued</th>
                            <th scope="col">Due</th>
                            <th scope="col" class="text-end">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td>
                                    <a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number ?? 'Draft' }}</a>
                                </td>
                                <td>
                                    {{ $invoice->customer_name }}
                                    @if ($invoice->customer_company_name)
                                        <div class="small text-body-secondary">{{ $invoice->customer_company_name }}</div>
                                    @endif
                                </td>
                                <td class="text-nowrap">{{ $invoice->issue_date->format('d M Y') }}</td>
                                <td class="text-nowrap">{{ $invoice->due_date->format('d M Y') }}</td>
                                <td class="text-end text-nowrap">{{ $invoice->money('total') }}</td>
                                <td>@include('invoices._status-badge')</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                    @if ($invoice->status->isDraft())
                                        <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <a href="{{ route('invoices.delete', $invoice) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Stacked list on small screens --}}
        <div class="list-group shadow-sm d-md-none">
            @foreach ($invoices as $invoice)
                <a href="{{ route('invoices.show', $invoice) }}" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="fw-semibold">{{ $invoice->invoice_number ?? 'Draft' }}</span>
                        <span class="text-nowrap">{{ $invoice->money('total') }}</span>
                    </div>
                    <div class="small">{{ $invoice->customer_name }}</div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1 small text-body-secondary">
                        @include('invoices._status-badge')
                        <span>Due {{ $invoice->due_date->format('d M Y') }}</span>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-3">
            {{ $invoices->links() }}
        </div>
    @endif
@endsection
