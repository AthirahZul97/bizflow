@extends('layouts.app')

@section('title', 'Delete recurring invoice')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Delete recurring invoice</h1>

                    <p>Delete <strong>{{ $recurring->name }}</strong>?</p>
                    <p class="text-body-secondary">
                        It has not generated any invoices, so nothing else is affected. This cannot be undone.
                    </p>

                    <form method="POST" action="{{ route('recurring-invoices.destroy', $recurring) }}" class="d-flex flex-wrap gap-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">Delete recurring invoice</button>
                        <a href="{{ route('recurring-invoices.show', $recurring) }}" class="btn btn-outline-secondary">Keep it</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
