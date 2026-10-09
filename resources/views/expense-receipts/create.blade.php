@extends('layouts.app')

@section('title', 'Scan a receipt')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <h1 class="h3 mb-3">Scan a receipt</h1>

            @if ($fake)
                @include('expense-receipts._fake-banner')
            @endif

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('expense-receipts.store') }}" enctype="multipart/form-data" novalidate
                          onsubmit="this.querySelector('[type=submit]').disabled = true">
                        @csrf

                        <div class="mb-3">
                            <label for="receipt" class="form-label">Receipt photo or PDF <span class="text-danger" aria-hidden="true">*</span></label>
                            <input id="receipt" type="file" name="receipt" required
                                   accept="image/jpeg,image/png,application/pdf" capture="environment"
                                   class="form-control form-control-lg @error('receipt') is-invalid @enderror" aria-describedby="receiptHelp">
                            @error('receipt')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div id="receiptHelp" class="form-text">JPG, PNG or PDF, up to {{ $maxMb }} MB. On a phone this opens the camera.</div>
                        </div>

                        <p class="small text-body-secondary">
                            The receipt is stored privately for your business. Nothing is saved as an expense until you
                            review the details and confirm.
                        </p>

                        <div class="d-grid d-sm-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Upload and read receipt</button>
                            <a href="{{ route('expense-receipts.index') }}" class="btn btn-outline-secondary btn-lg">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
