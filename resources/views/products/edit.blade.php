@extends('layouts.app')

@section('title', 'Edit '.$product->type->label())

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="h3 mb-3">Edit {{ strtolower($product->type->label()) }}</h1>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('products.update', $product) }}" novalidate>
                        @method('PUT')
                        @include('products._form')

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                            <a href="{{ route('products.show', $product) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
