@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Receipts</h1>
        @if ($enabled)
            <a href="{{ route('expense-receipts.create') }}" class="btn btn-primary">Scan a receipt</a>
        @endif
    </div>

    @if ($fake)
        @include('expense-receipts._fake-banner')
    @endif

    @if ($enabled)
        <p class="text-body-secondary small">
            @if ($limit === null)
                Receipt scans this month: {{ $used }} (no limit on your plan).
            @else
                Receipt scans this month: {{ $used }} of {{ $limit }}.
            @endif
        </p>
    @else
        <div class="alert alert-secondary">Receipt scanning is not available.</div>
    @endif

    @if ($receipts->isEmpty())
        <div class="card shadow-sm"><div class="card-body text-body-secondary">No receipts yet. Scan one to create an expense from it.</div></div>
    @else
        <div class="card shadow-sm">
            <ul class="list-group list-group-flush">
                @foreach ($receipts as $receipt)
                    <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="text-break">
                            <a href="{{ route('expense-receipts.show', $receipt) }}" class="fw-semibold">{{ $receipt->original_filename }}</a>
                            <div class="small text-body-secondary">Uploaded {{ $receipt->created_at->format('d M Y, H:i') }}</div>
                        </div>
                        <span class="badge {{ $receipt->status->badgeClass() }}">{{ $receipt->status->label() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-3">{{ $receipts->links() }}</div>
    @endif

    <p class="mt-3"><a href="{{ route('expenses.index') }}">&larr; Back to expenses</a></p>
@endsection
