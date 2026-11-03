@extends('layouts.app')

@section('title', $expense->description)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="h3 mb-1">{{ $expense->description }}</h1>
                    <span class="badge text-bg-light border">{{ $expense->category->label() }}</span>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('expenses.edit', $expense) }}" class="btn btn-outline-primary">Edit</a>
                    <a href="{{ route('expenses.delete', $expense) }}" class="btn btn-outline-danger">Delete</a>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Date</dt>
                        <dd class="col-sm-9">{{ $expense->expense_date->format('d M Y') }}</dd>

                        <dt class="col-sm-3">Amount</dt>
                        <dd class="col-sm-9 fw-semibold">{{ $expense->money() }}</dd>

                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">{{ $expense->category->label() }}</dd>

                        <dt class="col-sm-3">Payee</dt>
                        <dd class="col-sm-9">{{ $expense->payee ?? '—' }}</dd>

                        @php $receipt = $expense->receipt; @endphp
                        @if ($receipt)
                            <dt class="col-sm-3">Receipt</dt>
                            <dd class="col-sm-9"><a href="{{ route('expense-receipts.show', $receipt) }}">View scanned receipt</a></dd>
                        @endif

                        <dt class="col-sm-3">Notes</dt>
                        <dd class="col-sm-9 mb-0">
                            @if ($expense->notes)
                                {!! nl2br(e($expense->notes)) !!}
                            @else
                                <span class="text-body-secondary">&mdash;</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <a href="{{ route('expenses.index') }}">&larr; Back to expenses</a>
        </div>
    </div>
@endsection
