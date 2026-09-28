@extends('layouts.app')

@section('title', 'Customers')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Customers</h1>
        <a href="{{ route('customers.create') }}" class="btn btn-primary">New customer</a>
    </div>

    <form method="GET" action="{{ route('customers.index') }}" class="mb-3" role="search">
        <div class="input-group">
            <input type="search" name="search" value="{{ $search }}" class="form-control"
                   placeholder="Search by name, company, email or phone" aria-label="Search customers" maxlength="100">
            <button type="submit" class="btn btn-outline-secondary">Search</button>
            @if ($search !== '')
                <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($customers->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                @if ($search !== '')
                    <p class="mb-3">No customers match &ldquo;{{ $search }}&rdquo;.</p>
                    <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary">Clear search</a>
                @else
                    <p class="mb-3">You haven't added any customers yet.</p>
                    <a href="{{ route('customers.create') }}" class="btn btn-primary">Create your first customer</a>
                @endif
            </div>
        </div>
    @else
        {{-- Table on medium screens and up --}}
        <div class="card shadow-sm d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Company</th>
                            <th scope="col">Email</th>
                            <th scope="col">Phone</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $customer)
                            <tr>
                                <td><a href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a></td>
                                <td>{{ $customer->company_name }}</td>
                                <td>{{ $customer->email }}</td>
                                <td>{{ $customer->phone }}</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                    <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="{{ route('customers.delete', $customer) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Stacked list on small screens --}}
        <div class="list-group shadow-sm d-md-none">
            @foreach ($customers as $customer)
                <div class="list-group-item">
                    <a href="{{ route('customers.show', $customer) }}" class="fw-semibold">{{ $customer->name }}</a>
                    @if ($customer->company_name)
                        <div class="small text-body-secondary">{{ $customer->company_name }}</div>
                    @endif
                    @if ($customer->email)
                        <div class="small text-break">{{ $customer->email }}</div>
                    @endif
                    <div class="d-flex gap-2 mt-2">
                        <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                        <a href="{{ route('customers.delete', $customer) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3">
            {{ $customers->links() }}
        </div>
    @endif
@endsection
