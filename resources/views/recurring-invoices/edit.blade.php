@extends('layouts.app')

@section('title', 'Edit recurring invoice')

@section('content')
    <h1 class="h3 mb-1">Edit recurring invoice</h1>
    <p class="text-body-secondary mb-3">Changes apply to invoices generated from now on. Invoices already generated are never changed.</p>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('recurring-invoices.update', $recurring) }}" novalidate data-invoice-form data-currency="{{ config('bizflow.currency.symbol') }}">
                @method('PUT')
                @include('recurring-invoices._form')

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a href="{{ route('recurring-invoices.show', $recurring) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
