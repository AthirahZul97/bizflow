@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="h3 mb-1">{{ $product->name }}</h1>
                    <div class="d-flex gap-1">
                        @include('products._type-badge')
                        @include('products._status-badge')
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-outline-primary">Edit</a>
                    <a href="{{ route('products.delete', $product) }}" class="btn btn-outline-danger">Delete</a>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">SKU</dt>
                        <dd class="col-sm-9">{{ $product->sku ?? '—' }}</dd>

                        <dt class="col-sm-3">Unit</dt>
                        <dd class="col-sm-9">{{ $product->unit ?? '—' }}</dd>

                        <dt class="col-sm-3">Selling price</dt>
                        <dd class="col-sm-9">{{ $product->money('selling_price') }}</dd>

                        <dt class="col-sm-3">Cost price <span class="badge text-bg-light border">Internal</span></dt>
                        <dd class="col-sm-9">{{ $product->money('cost_price') ?? '—' }}</dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9 mb-0">
                            @if ($product->description)
                                {!! nl2br(e($product->description)) !!}
                            @else
                                <span class="text-body-secondary">&mdash;</span>
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <a href="{{ route('products.index') }}">&larr; Back to products &amp; services</a>
        </div>
    </div>
@endsection
