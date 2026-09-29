@extends('layouts.app')

@section('title', 'Customer report')

@section('content')
    @include('reports._header')

    @php($money = fn (string $amount) => \App\Support\Money::format($amount))

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-1">Customers</h2>
            <p class="small text-body-secondary mb-3">
                Invoiced and received are for {{ $period->label() }}; outstanding and overdue are as of today.
                Names are as billed on each customer's most recent invoice here.
            </p>

            @if ($rows->isEmpty())
                <p class="text-body-secondary mb-0" data-report-empty>No invoiced, received or outstanding amounts for this period.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col" class="text-end">Invoices issued</th>
                                <th scope="col" class="text-end">Invoiced</th>
                                <th scope="col" class="text-end">Received</th>
                                <th scope="col" class="text-end">Outstanding</th>
                                <th scope="col" class="text-end">Overdue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php($snapshot = $names->get($row->latest_invoice_id))
                                <tr data-customer-row="{{ $row->customer_id }}">
                                    <td>
                                        <a href="{{ route('customers.show', $row->customer_id) }}">{{ $snapshot?->customer_name }}</a>
                                        @if ($snapshot?->customer_company_name)
                                            <div class="small text-body-secondary">{{ $snapshot->customer_company_name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $row->invoiced_count }}</td>
                                    <td class="text-end text-nowrap">{{ $money($row->invoiced) }}</td>
                                    <td class="text-end text-nowrap">{{ $money($row->received) }}</td>
                                    <td class="text-end text-nowrap">{{ $money($row->outstanding) }}</td>
                                    <td @class(['text-end', 'text-nowrap', 'text-danger' => $row->overdue !== '0.00'])>{{ $money($row->overdue) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold" data-customer-row="total">
                                <td>Total <span class="small text-body-secondary fw-normal">(all {{ $rows->total() }} {{ Str::plural('customer', $rows->total()) }})</span></td>
                                <td class="text-end">{{ $totals['invoiced_count'] }}</td>
                                <td class="text-end text-nowrap">{{ $money($totals['invoiced']) }}</td>
                                <td class="text-end text-nowrap">{{ $money($totals['received']) }}</td>
                                <td class="text-end text-nowrap">{{ $money($totals['outstanding']) }}</td>
                                <td class="text-end text-nowrap">{{ $money($totals['overdue']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-3 d-print-none">{{ $rows->links() }}</div>
            @endif
        </div>
    </div>

    @include('reports._footnote')
@endsection
