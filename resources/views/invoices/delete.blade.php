@extends('layouts.app')

@section('title', 'Delete draft invoice')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Delete draft invoice</h1>

                    <p>Are you sure you want to delete this draft for <strong>{{ $invoice->customer_name }}</strong>
                        ({{ $invoice->money('total') }})?</p>
                    <p class="text-body-secondary">This permanently removes the draft and its lines. Drafts have no invoice number, so none is lost.</p>

                    <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" class="d-flex gap-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">Delete draft</button>
                        <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
