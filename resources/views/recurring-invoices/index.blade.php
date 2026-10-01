@extends('layouts.app')

@section('title', 'Recurring invoices')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Recurring invoices</h1>
        <a href="{{ route('recurring-invoices.create') }}" class="btn btn-primary">New recurring invoice</a>
    </div>

    @if ($recurringInvoices->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <p class="mb-1">You haven't set up any recurring invoices yet.</p>
                <p class="text-body-secondary mb-3">A recurring invoice creates a draft invoice for a customer every week, month or year.</p>
                <a href="{{ route('recurring-invoices.create') }}" class="btn btn-primary">Create your first recurring invoice</a>
            </div>
        </div>
    @else
        {{-- Table on medium screens and up --}}
        <div class="card shadow-sm d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Repeats</th>
                            <th scope="col">Next invoice</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recurringInvoices as $recurring)
                            <tr>
                                <td>
                                    <a href="{{ route('recurring-invoices.show', $recurring) }}">{{ $recurring->name }}</a>
                                </td>
                                <td>{{ $recurring->customer->name }}</td>
                                <td>{{ $recurring->frequency->label() }}</td>
                                <td>@include('recurring-invoices._next', ['compact' => true])</td>
                                <td class="text-end text-nowrap">{{ isset($totals[$recurring->id]) ? \App\Support\Money::format($totals[$recurring->id]->total) : '—' }}</td>
                                <td>@include('recurring-invoices._status-badge')</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('recurring-invoices.show', $recurring) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                    @unless ($recurring->isCancelled())
                                        <a href="{{ route('recurring-invoices.edit', $recurring) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Stacked list on small screens --}}
        <div class="list-group shadow-sm d-md-none">
            @foreach ($recurringInvoices as $recurring)
                <a href="{{ route('recurring-invoices.show', $recurring) }}" class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="fw-semibold">{{ $recurring->name }}</span>
                        <span class="text-nowrap">{{ isset($totals[$recurring->id]) ? \App\Support\Money::format($totals[$recurring->id]->total) : '—' }}</span>
                    </div>
                    <div class="small">{{ $recurring->customer->name }} · {{ $recurring->frequency->label() }}</div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1 small text-body-secondary">
                        @include('recurring-invoices._status-badge')
                        @include('recurring-invoices._next', ['compact' => true])
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-3">
            {{ $recurringInvoices->links() }}
        </div>
    @endif
@endsection
