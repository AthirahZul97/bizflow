@extends('layouts.app')

@section('title', 'Delete '.$product->type->label())

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Delete {{ strtolower($product->type->label()) }}</h1>

                    @if ($usedOnInvoices)
                        <div class="alert alert-warning">
                            <strong>{{ $product->name }}</strong> is used on invoices and cannot be deleted.
                            @if ($product->is_active)
                                <a href="{{ route('products.edit', $product) }}">Mark it inactive</a> to stop offering it.
                            @endif
                        </div>
                        <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Back to item</a>
                    @elseif ($usedOnRecurringInvoices)
                        <div class="alert alert-warning">
                            <strong>{{ $product->name }}</strong> is used on a recurring invoice and cannot be deleted.
                            Remove it from the recurring invoice first.
                        </div>
                        <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Back to item</a>
                    @else
                        <p>Are you sure you want to delete <strong>{{ $product->name }}</strong>?</p>
                        <p class="text-body-secondary">This permanently removes the item and cannot be undone.</p>

                        @if ($product->is_active)
                            <div class="alert alert-info small">
                                If you only want to stop offering it, <a href="{{ route('products.edit', $product) }}">mark it inactive</a> instead.
                            </div>
                        @endif

                        <form method="POST" action="{{ route('products.destroy', $product) }}" class="d-flex gap-2">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">Delete {{ strtolower($product->type->label()) }}</button>
                            <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Cancel</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
