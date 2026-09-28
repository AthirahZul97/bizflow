@extends('layouts.app')

@section('title', 'Delete expense')

@section('content')
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="card border-danger shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Delete expense</h1>

                    <p>Are you sure you want to delete <strong>{{ $expense->description }}</strong>
                        ({{ $expense->money() }}) from {{ $expense->expense_date->format('d M Y') }}?</p>
                    <p class="text-body-secondary">This permanently removes the expense and cannot be undone.</p>

                    <form method="POST" action="{{ route('expenses.destroy', $expense) }}" class="d-flex gap-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">Delete expense</button>
                        <a href="{{ route('expenses.show', $expense) }}" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
