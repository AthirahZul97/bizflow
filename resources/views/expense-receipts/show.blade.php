@extends('layouts.app')

@section('title', 'Receipt')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-10">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h1 class="h3 mb-0 text-break">{{ $receipt->original_filename }}</h1>
                <span class="badge {{ $receipt->status->badgeClass() }} fs-6">{{ $receipt->status->label() }}</span>
            </div>

            @if ($fake)
                @include('expense-receipts._fake-banner')
            @endif

            @if ($duplicateFile)
                <div class="alert alert-warning" role="alert" data-testid="duplicate-file-warning">
                    This exact file was already uploaded on {{ $duplicateFile->created_at->format('d M Y') }}
                    (<a href="{{ route('expense-receipts.show', $duplicateFile) }}">view that receipt</a>).
                    You can continue, but check you are not recording the same expense twice.
                </div>
            @endif

            @switch($receipt->status)
                @case(\App\Enums\ExpenseReceiptStatus::Queued)
                @case(\App\Enums\ExpenseReceiptStatus::Processing)
                    @include('expense-receipts._processing')
                    @break
                @case(\App\Enums\ExpenseReceiptStatus::Review)
                    @include('expense-receipts._review')
                    @break
                @case(\App\Enums\ExpenseReceiptStatus::Failed)
                @case(\App\Enums\ExpenseReceiptStatus::Unreadable)
                    @include('expense-receipts._failed')
                    @break
                @case(\App\Enums\ExpenseReceiptStatus::Confirmed)
                    @include('expense-receipts._confirmed')
                    @break
                @default
                    <div class="card shadow-sm"><div class="card-body">This receipt was discarded and its file deleted.</div></div>
            @endswitch

            <p class="mt-3"><a href="{{ route('expense-receipts.index') }}">&larr; All receipts</a></p>
        </div>
    </div>
@endsection
