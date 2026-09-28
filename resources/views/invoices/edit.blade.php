@extends('layouts.app')

@section('title', 'Edit draft invoice')

@section('content')
    <h1 class="h3 mb-3">Edit draft invoice</h1>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('invoices.update', $invoice) }}" novalidate data-invoice-form data-currency="{{ config('bizflow.currency.symbol') }}">
                @method('PUT')
                @include('invoices._form')

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save draft</button>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
