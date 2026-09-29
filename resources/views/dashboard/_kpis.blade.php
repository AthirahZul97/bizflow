@php
    $negativeNet = str_starts_with($netCash, '-');
@endphp

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-5 g-3 mb-4">
    <div class="col">
        <div class="card shadow-sm h-100" data-kpi="received">
            <div class="card-body">
                <div class="small text-body-secondary">Received</div>
                <div class="fs-4 fw-semibold">{{ \App\Support\Money::format($received['amount']) }}</div>
                <div class="small text-body-secondary">{{ $received['count'] }} {{ Str::plural('invoice', $received['count']) }} marked paid</div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card shadow-sm h-100" data-kpi="expenses">
            <div class="card-body">
                <div class="small text-body-secondary">Expenses</div>
                <div class="fs-4 fw-semibold">{{ \App\Support\Money::format($expenses['amount']) }}</div>
                <a class="small" href="{{ route('expenses.index', ['from' => $period->startDate(), 'to' => $period->endDate()]) }}">{{ $expenses['count'] }} {{ Str::plural('expense', $expenses['count']) }}</a>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card shadow-sm h-100" data-kpi="net-cash">
            <div class="card-body">
                <div class="small text-body-secondary">Net cash (estimate)</div>
                <div @class(['fs-4', 'fw-semibold', 'text-danger' => $negativeNet])>{{ \App\Support\Money::format($netCash) }}</div>
                <div class="small text-body-secondary">Received minus expenses</div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card shadow-sm h-100" data-kpi="invoiced">
            <div class="card-body">
                <div class="small text-body-secondary">Invoiced</div>
                <div class="fs-4 fw-semibold">{{ \App\Support\Money::format($invoiced['amount']) }}</div>
                <div class="small text-body-secondary">{{ $invoiced['count'] }} {{ Str::plural('invoice', $invoiced['count']) }} issued</div>
            </div>
        </div>
    </div>

    <div class="col">
        <div class="card shadow-sm h-100" data-kpi="outstanding">
            <div class="card-body">
                <div class="small text-body-secondary">Outstanding <span class="text-body-tertiary">(as of today)</span></div>
                <div class="fs-4 fw-semibold">{{ \App\Support\Money::format($outstanding['amount']) }}</div>
                @if ($outstanding['overdueCount'] > 0)
                    <a class="small text-danger" href="{{ route('invoices.index', ['status' => 'overdue']) }}">{{ \App\Support\Money::format($outstanding['overdueAmount']) }} overdue ({{ $outstanding['overdueCount'] }})</a>
                @else
                    <div class="small text-body-secondary">Nothing overdue</div>
                @endif
            </div>
        </div>
    </div>
</div>
