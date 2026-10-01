@extends('layouts.app')

@section('title', 'Cancel recurring invoice')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Cancel recurring invoice</h1>

                    <p>Cancel <strong>{{ $recurring->name }}</strong>?</p>
                    <p class="text-body-secondary">
                        No more invoices will be generated from it. Invoices it has already generated are not changed
                        or deleted. This cannot be undone; to bill this customer regularly again, create a new
                        recurring invoice.
                    </p>

                    <form method="POST" action="{{ route('recurring-invoices.cancel', $recurring) }}" class="d-flex flex-wrap gap-2">
                        @csrf
                        <button type="submit" class="btn btn-danger">Cancel recurring invoice</button>
                        <a href="{{ route('recurring-invoices.show', $recurring) }}" class="btn btn-outline-secondary">Keep it</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
