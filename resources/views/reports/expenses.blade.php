@extends('layouts.app')

@section('title', 'Expense report')

@section('content')
    @include('reports._header')

    @php($money = fn (string $amount) => \App\Support\Money::format($amount))

    <div class="card shadow-sm mb-4" data-report-expense-total>
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="small text-body-secondary">Expenses, {{ $period->label() }}</div>
                <div class="fs-4 fw-semibold">{{ $money($totals['amount']) }}</div>
                <div class="small text-body-secondary">{{ $totals['count'] }} {{ Str::plural('expense', $totals['count']) }}</div>
            </div>
            <a href="{{ route('expenses.index', ['from' => $period->startDate(), 'to' => $period->endDate()]) }}" class="d-print-none">View these expenses &rarr;</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5 mb-3">By category</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Category</th>
                            <th scope="col" class="text-end">Count</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" style="min-width: 10rem">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $row)
                            <tr @class(['text-body-secondary' => $row['count'] === 0]) data-category-row="{{ $row['category']->value }}">
                                <td>
                                    <a href="{{ route('expenses.index', ['category' => $row['category']->value, 'from' => $period->startDate(), 'to' => $period->endDate()]) }}"
                                       @class(['link-secondary' => $row['count'] === 0])>{{ $row['category']->label() }}</a>
                                </td>
                                <td class="text-end">{{ $row['count'] }}</td>
                                <td class="text-end text-nowrap">{{ $money($row['amount']) }}</td>
                                <td>
                                    @if ($row['share'] === null)
                                        &mdash;
                                    @else
                                        {{-- Real spending that rounds to 0% is shown as "<1%" rather than a misleading "0%". --}}
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" role="progressbar" aria-label="{{ $row['category']->label() }} share of expenses"
                                                 aria-valuenow="{{ $row['share'] }}" aria-valuemin="0" aria-valuemax="100" style="height: 0.5rem">
                                                <div class="progress-bar" style="width: {{ max($row['share'], 1) }}%"></div>
                                            </div>
                                            <span class="small text-nowrap">{{ $row['share'] === 0 ? '<1' : $row['share'] }}%</span>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold" data-category-row="total">
                            <td>Total</td>
                            <td class="text-end">{{ $totals['count'] }}</td>
                            <td class="text-end text-nowrap">{{ $money($totals['amount']) }}</td>
                            <td>{{ $totals['count'] > 0 ? '100%' : '—' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if ($totals['count'] === 0)
                <p class="small text-body-secondary mt-2 mb-0" data-report-empty>No expenses recorded in this period.</p>
            @endif
        </div>
    </div>

    @include('reports._footnote')
@endsection
