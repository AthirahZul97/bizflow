@extends('layouts.app')

@section('title', 'New expense')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="h3 mb-3">New expense</h1>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('expenses.store') }}" novalidate>
                        @include('expenses._form')

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save expense</button>
                            <a href="{{ route('expenses.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
