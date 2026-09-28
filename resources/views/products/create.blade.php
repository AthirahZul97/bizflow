@extends('layouts.app')

@section('title', 'New product or service')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="h3 mb-3">New product or service</h1>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('products.store') }}" novalidate>
                        @include('products._form')

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save item</button>
                            <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
