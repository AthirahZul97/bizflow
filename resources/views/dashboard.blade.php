@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Dashboard</h1>
            <p class="mb-0">Welcome, <strong>{{ Auth::user()->name }}</strong>.
                <span class="text-body-secondary">Showing {{ $period->label() }}.</span></p>
        </div>
        @include('partials._period-selector', [
            'periodRoute' => 'dashboard',
            'periodPresets' => \App\Support\ReportingPeriod::DASHBOARD_PRESETS,
        ])
    </div>

    @if ($period->fellBack)
        <div class="alert alert-warning py-2">That date range wasn't valid, so {{ $period->defaultLabel() }} is shown.</div>
    @endif

    @if ($isNewAccount)
        @include('dashboard._get-started')
    @endif

    @include('dashboard._attention')
    @include('dashboard._kpis')

    <div class="row g-3 mb-4">
        <div class="col-lg-6">@include('dashboard._status')</div>
        <div class="col-lg-6">@include('dashboard._categories')</div>
    </div>

    @include('dashboard._trend')
    @include('dashboard._recent')

    <p class="small text-body-secondary mb-0">
        <strong>Received</strong> is the total of invoices marked as paid, by payment date.
        <strong>Invoiced</strong> is issued and paid invoices, by issue date. Drafts and cancelled invoices are not counted.
        Invoice amounts include any tax on the invoice.
        <strong>Net cash (estimate)</strong>: Received minus expenses for this period. A simple estimate, not accounting profit.
    </p>
@endsection
