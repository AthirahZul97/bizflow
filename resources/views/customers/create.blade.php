@extends('layouts.app')

@section('title', 'New customer')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="h3 mb-3">New customer</h1>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('customers.store') }}" novalidate>
                        @include('customers._form')

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save customer</button>
                            <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
