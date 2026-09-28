@extends('layouts.app')

@section('title', 'Products & Services')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Products &amp; Services</h1>
        <a href="{{ route('products.create') }}" class="btn btn-primary">New item</a>
    </div>

    <form method="GET" action="{{ route('products.index') }}" class="row g-2 mb-3" role="search">
        <div class="col-md-6">
            <input type="search" name="search" value="{{ $search }}" class="form-control"
                   placeholder="Search by name, SKU or description" aria-label="Search products and services" maxlength="100">
        </div>
        <div class="col-6 col-md-2">
            <select name="type" class="form-select" aria-label="Filter by type">
                <option value="">All types</option>
                @foreach (\App\Enums\ProductType::cases() as $case)
                    <option value="{{ $case->value }}" @selected($type === $case)>{{ $case->label() }}s</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="status" class="form-select" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="inactive" @selected($status === 'inactive')>Inactive</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-secondary flex-grow-1">Filter</button>
            @if ($filtered)
                <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Clear</a>
            @endif
        </div>
    </form>

    @if ($products->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                @if ($filtered)
                    <p class="mb-3">No items match your search or filters.</p>
                    <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Clear filters</a>
                @else
                    <p class="mb-3">You haven't added any products or services yet.</p>
                    <a href="{{ route('products.create') }}" class="btn btn-primary">Add your first product or service</a>
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
                            <th scope="col">Type</th>
                            <th scope="col" class="text-end">Price</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr>
                                <td>
                                    <a href="{{ route('products.show', $product) }}">{{ $product->name }}</a>
                                    @if ($product->sku)
                                        <div class="small text-body-secondary">{{ $product->sku }}</div>
                                    @endif
                                </td>
                                <td>@include('products._type-badge')</td>
                                <td class="text-end text-nowrap">
                                    {{ $product->money('selling_price') }}
                                    @if ($product->unit)
                                        <span class="small text-body-secondary">/ {{ $product->unit }}</span>
                                    @endif
                                </td>
                                <td>@include('products._status-badge')</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('products.show', $product) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                    <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="{{ route('products.delete', $product) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Stacked list on small screens --}}
        <div class="list-group shadow-sm d-md-none">
            @foreach ($products as $product)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <a href="{{ route('products.show', $product) }}" class="fw-semibold">{{ $product->name }}</a>
                        <span class="text-nowrap">{{ $product->money('selling_price') }}</span>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        @include('products._type-badge')
                        @include('products._status-badge')
                        @if ($product->sku)
                            <span class="small text-body-secondary">{{ $product->sku }}</span>
                        @endif
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <a href="{{ route('products.edit', $product) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                        <a href="{{ route('products.delete', $product) }}" class="btn btn-sm btn-outline-danger">Delete</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3">
            {{ $products->links() }}
        </div>
    @endif
@endsection
