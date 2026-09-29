{{-- Shared report header. Expects $period; optional $periodQuery (kept by the period selector) and $invoiceQuery (kept by the Invoices tab). --}}
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
    <div>
        <h1 class="h3 mb-1">Reports</h1>
        <p class="mb-0 text-body-secondary" data-report-period>Showing {{ $period->label() }}.</p>
    </div>
    <div class="d-flex flex-wrap align-items-start gap-2">
        @include('partials._period-selector', [
            'periodRoute' => request()->route()->getName(),
            'periodPresets' => \App\Support\ReportingPeriod::REPORT_PRESETS,
            'periodQuery' => $periodQuery ?? [],
        ])
        <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" data-print>Print</button>
    </div>
</div>

@if ($period->fellBack)
    <div class="alert alert-warning py-2">That date range wasn't valid, so {{ $period->defaultLabel() }} is shown.</div>
@endif

@include('reports._nav')
