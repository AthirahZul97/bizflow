@extends('layouts.app')

@section('title', 'New recurring invoice')

@section('content')
    <h1 class="h3 mb-1">New recurring invoice</h1>
    <p class="text-body-secondary mb-3">Creates a draft invoice on each date of the schedule, for you to review and issue.</p>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('recurring-invoices.store') }}" novalidate data-invoice-form data-currency="{{ config('bizflow.currency.symbol') }}">
                @include('recurring-invoices._form')

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save recurring invoice</button>
                    <a href="{{ route('recurring-invoices.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
