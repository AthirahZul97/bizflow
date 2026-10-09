@extends('layouts.app')

@section('title', 'Discard receipt')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Discard receipt</h1>

                    <p>Discard <strong class="text-break">{{ $receipt->original_filename }}</strong>?</p>
                    <p class="text-body-secondary">
                        The uploaded file and anything read from it are permanently deleted. No expense is created or changed.
                    </p>

                    <form method="POST" action="{{ route('expense-receipts.destroy', $receipt) }}" class="d-flex gap-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">Discard receipt</button>
                        <a href="{{ route('expense-receipts.show', $receipt) }}" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
