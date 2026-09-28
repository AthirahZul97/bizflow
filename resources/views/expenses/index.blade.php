@extends('layouts.app')

@section('title', 'Expenses')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Expenses</h1>
        <a href="{{ route('expenses.create') }}" class="btn btn-primary">New expense</a>
    </div>

    <form method="GET" action="{{ route('expenses.index') }}" class="row g-2 mb-3" role="search">
        <div class="col-md-4">
            <label for="search" class="form-label small mb-1">Search</label>
            <input id="search" type="search" name="search" value="{{ $search }}" class="form-control"
                   placeholder="Description or payee" maxlength="100">
        </div>
        <div class="col-md-3">
            <label for="category" class="form-label small mb-1">Category</label>
            <select id="category" name="category" class="form-select">
                <option value="">All categories</option>
                @foreach (\App\Enums\ExpenseCategory::cases() as $case)
                    <option value="{{ $case->value }}" @selected($category === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="from" class="form-label small mb-1">From</label>
            <input id="from" type="date" name="from" value="{{ $from }}" class="form-control">
        </div>
        <div class="col-6 col-md-2">
            <label for="to" class="form-label small mb-1">To</label>
            <input id="to" type="date" name="to" value="{{ $to }}" class="form-control">
        </div>
        <div class="col-md-1 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-outline-secondary w-100">Filter</button>
        </div>
        @if ($filtered)
            <div class="col-12">
                <a href="{{ route('expenses.index') }}" class="small">Clear filters</a>
            </div>
        @endif
    </form>

    @if ($expenses->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                @if ($filtered)
                    <p class="mb-3">No expenses match your search or filters.</p>
                    <a href="{{ route('expenses.index') }}" class="btn btn-outline-secondary">Clear filters</a>
                @else
                    <p class="mb-3">You haven't recorded any expenses yet.</p>
                    <a href="{{ route('expenses.create') }}" class="btn btn-primary">Record your first expense</a>
                @endif
            </div>
        </div>
    @else
        <p class="mb-2" data-expense-summary>
            {{ $count }} {{ Str::plural('expense', $count) }} &middot; Total <strong>{{ $total }}</strong>
        </p>

        {{-- Table on medium screens and up --}}
        <div class="card shadow-sm d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Description</th>
                            <th scope="col">Category</th>
                            <th scope="col" class="text-end">Amount</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($expenses as $expense)
                            <tr>
                                <td class="text-nowrap">{{ $expense->expense_date->format('d M Y') }}</td>
                                <td>
                                    <a href="{{ route('expenses.show', $expense) }}">{{ $expense->description }}</a>
                                    @if ($expense->payee)
                                        <div class="small text-body-secondary">{{ $expense->payee }}</div>
                                    @endif
                                </td>
                                <td><span class="badge text-bg-light border">{{ $expense->category->label() }}</span></td>
                                <td class="text-end text-nowrap">{{ $expense->money() }}</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('expenses.show', $expense) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                    <a href="{{ route('expenses.edit', $expense) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="{{ route('expenses.delete', $expense) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Stacked list on small screens --}}
        <div class="list-group shadow-sm d-md-none">
            @foreach ($expenses as $expense)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <a href="{{ route('expenses.show', $expense) }}" class="fw-semibold">{{ $expense->description }}</a>
                        <span class="text-nowrap">{{ $expense->money() }}</span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1 small text-body-secondary">
                        <span>{{ $expense->expense_date->format('d M Y') }}</span>
                        <span class="badge text-bg-light border">{{ $expense->category->label() }}</span>
                        @if ($expense->payee)
                            <span>{{ $expense->payee }}</span>
                        @endif
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <a href="{{ route('expenses.edit', $expense) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                        <a href="{{ route('expenses.delete', $expense) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3">
            {{ $expenses->links() }}
        </div>
    @endif
@endsection
