@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    @include('reports._header')

    @php
        $money = fn (string $amount) => \App\Support\Money::format($amount);
        $negative = fn (string $amount) => str_starts_with($amount, '-');
        $cards = [
            'received' => ['Received', $received, 'invoice', 'marked paid'],
            'invoiced' => ['Invoiced', $invoiced, 'invoice', 'issued'],
            'expenses' => ['Expenses', $expenses, 'expense', 'recorded'],
        ];
    @endphp

    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 mb-4">
        @foreach ($cards as $key => [$label, $figure, $noun, $verb])
            <div class="col">
                <div class="card shadow-sm h-100" data-report-card="{{ $key }}">
                    <div class="card-body">
                        <div class="small text-body-secondary">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">{{ $money($figure['amount']) }}</div>
                        <div class="small text-body-secondary">{{ $figure['count'] }} {{ Str::plural($noun, $figure['count']) }} {{ $verb }}</div>
                    </div>
                </div>
            </div>
        @endforeach
        <div class="col">
            <div class="card shadow-sm h-100" data-report-card="net-cash">
                <div class="card-body">
                    <div class="small text-body-secondary">Net cash (estimate)</div>
                    <div @class(['fs-4', 'fw-semibold', 'text-danger' => $negative($netCash)])>{{ $money($netCash) }}</div>
                    <div class="small text-body-secondary">Received minus expenses</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4" data-report-months>
        <div class="card-body">
            <h2 class="h5 mb-3">By month</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Month</th>
                            <th scope="col" class="text-end">Received</th>
                            <th scope="col" class="text-end">Invoiced</th>
                            <th scope="col" class="text-end">Expenses</th>
                            <th scope="col" class="text-end">Net cash</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $row)
                            <tr data-month="{{ $row['key'] }}">
                                <td class="text-nowrap">{{ $row['label'] }}</td>
                                <td class="text-end text-nowrap">{{ $money($row['received']['amount']) }} <span class="text-body-secondary small">({{ $row['received']['count'] }})</span></td>
                                <td class="text-end text-nowrap">{{ $money($row['invoiced']['amount']) }} <span class="text-body-secondary small">({{ $row['invoiced']['count'] }})</span></td>
                                <td class="text-end text-nowrap">{{ $money($row['expenses']['amount']) }} <span class="text-body-secondary small">({{ $row['expenses']['count'] }})</span></td>
                                <td @class(['text-end', 'text-nowrap', 'text-danger' => $negative($row['net'])])>{{ $money($row['net']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold" data-month="total">
                            <td>Total</td>
                            <td class="text-end text-nowrap">{{ $money($received['amount']) }} <span class="text-body-secondary small">({{ $received['count'] }})</span></td>
                            <td class="text-end text-nowrap">{{ $money($invoiced['amount']) }} <span class="text-body-secondary small">({{ $invoiced['count'] }})</span></td>
                            <td class="text-end text-nowrap">{{ $money($expenses['amount']) }} <span class="text-body-secondary small">({{ $expenses['count'] }})</span></td>
                            <td @class(['text-end', 'text-nowrap', 'text-danger' => $negative($netCash)])>{{ $money($netCash) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if (count($months) === 1)
                <p class="small text-body-secondary mt-2 mb-0" data-report-one-month-hint>Choose This year or a custom range to compare months.</p>
            @endif
            @if ($received['count'] === 0 && $invoiced['count'] === 0 && $expenses['count'] === 0)
                <p class="small text-body-secondary mt-2 mb-0" data-report-empty>No invoices or expenses in this period.</p>
            @endif
        </div>
    </div>

    <div class="card shadow-sm" data-report-position>
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="h6 mb-1">As of today <span class="small text-body-secondary fw-normal">({{ today()->format('j M Y') }}, not affected by the period)</span></h2>
                <div>
                    Outstanding: <strong>{{ $money($position['outstandingAmount']) }}</strong>
                    ({{ $position['outstandingCount'] }} {{ Str::plural('invoice', $position['outstandingCount']) }})
                    &middot;
                    <span @class(['text-danger' => $position['overdueCount'] > 0])>Overdue: <strong>{{ $money($position['overdueAmount']) }}</strong>
                        ({{ $position['overdueCount'] }} {{ Str::plural('invoice', $position['overdueCount']) }})</span>
                </div>
            </div>
            <a href="{{ route('reports.invoices', ['view' => 'outstanding'] + $period->query()) }}" class="d-print-none">View outstanding invoices &rarr;</a>
        </div>
    </div>

    @include('reports._footnote')
@endsection
