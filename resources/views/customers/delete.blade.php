@extends('layouts.app')

@section('title', 'Delete customer')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Delete customer</h1>

                    @if ($hasInvoices)
                        <div class="alert alert-warning">
                            <strong>{{ $customer->name }}</strong> has invoices and cannot be deleted.
                            Invoices keep a record of who they were billed to.
                        </div>
                        <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary">Back to customer</a>
                    @else
                        <p>Are you sure you want to delete <strong>{{ $customer->name }}</strong>?</p>
                        <p class="text-body-secondary">This permanently removes the customer and cannot be undone.</p>

                        <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="d-flex gap-2">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">Delete customer</button>
                            <a href="{{ route('customers.show', $customer) }}" class="btn btn-outline-secondary">Cancel</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
