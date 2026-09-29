@php
    $tabs = [
        'reports.summary' => ['Summary', []],
        'reports.customers' => ['Customers', []],
        'reports.invoices' => ['Invoices', $invoiceQuery ?? []],
        'reports.expenses' => ['Expenses', []],
    ];
@endphp

<ul class="nav nav-tabs mb-4 d-print-none" data-report-tabs>
    @foreach ($tabs as $route => [$label, $extra])
        <li class="nav-item">
            <a href="{{ route($route, $period->query() + $extra) }}"
               @class(['nav-link', 'active' => request()->routeIs($route)])
               @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        </li>
    @endforeach
</ul>
