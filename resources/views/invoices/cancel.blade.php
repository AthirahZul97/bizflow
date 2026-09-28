@extends('layouts.app')

@section('title', 'Cancel invoice')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Cancel invoice {{ $invoice->invoice_number }}</h1>

                    <p>Cancel invoice <strong>{{ $invoice->invoice_number }}</strong> for
                        <strong>{{ $invoice->customer_name }}</strong> ({{ $invoice->money('total') }})?</p>
                    <p class="text-body-secondary">
                        A cancelled invoice keeps its number and stays on record, but no longer counts as money owed.
                        This cannot be undone. To bill the customer again, create a new invoice.
                    </p>

                    <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="d-flex gap-2">
                        @csrf
                        <button type="submit" class="btn btn-danger">Cancel invoice</button>
                        <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Keep invoice</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
