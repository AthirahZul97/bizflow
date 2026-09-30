@extends('layouts.app')

@section('title', 'Business profile')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="h3 mb-1">Business profile</h1>
            <p class="text-body-secondary mb-3">These details appear as the seller on your invoices and PDFs.</p>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('business.profile.update') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <h2 class="h6 text-body-secondary text-uppercase mb-3">Business</h2>

                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label for="name" class="form-label">Business name <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="name" type="text" name="name" value="{{ old('name', $business->name) }}"
                                       class="form-control @error('name') is-invalid @enderror" required maxlength="255" autocomplete="organization">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="registration_number" class="form-label">Registration no.</label>
                                <input id="registration_number" type="text" name="registration_number" value="{{ old('registration_number', $business->registration_number) }}"
                                       class="form-control @error('registration_number') is-invalid @enderror" maxlength="50" aria-describedby="registrationHelp">
                                @error('registration_number')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div id="registrationHelp" class="form-text">For example your SSM registration number.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="sst_number" class="form-label">SST no.</label>
                                <input id="sst_number" type="text" name="sst_number" value="{{ old('sst_number', $business->sst_number) }}"
                                       class="form-control @error('sst_number') is-invalid @enderror" maxlength="50" aria-describedby="sstHelp">
                                @error('sst_number')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div id="sstHelp" class="form-text">Leave blank if you are not SST-registered.</div>
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input id="email" type="email" name="email" value="{{ old('email', $business->email) }}"
                                       class="form-control @error('email') is-invalid @enderror" maxlength="255" autocomplete="email">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone</label>
                                <input id="phone" type="tel" name="phone" value="{{ old('phone', $business->phone) }}"
                                       class="form-control @error('phone') is-invalid @enderror" maxlength="30" autocomplete="tel">
                                @error('phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <h2 class="h6 text-body-secondary text-uppercase mb-3">Address</h2>

                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <label for="address_line_1" class="form-label">Address line 1</label>
                                <input id="address_line_1" type="text" name="address_line_1" value="{{ old('address_line_1', $business->address_line_1) }}"
                                       class="form-control @error('address_line_1') is-invalid @enderror" maxlength="255" autocomplete="address-line1">
                                @error('address_line_1')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="address_line_2" class="form-label">Address line 2</label>
                                <input id="address_line_2" type="text" name="address_line_2" value="{{ old('address_line_2', $business->address_line_2) }}"
                                       class="form-control @error('address_line_2') is-invalid @enderror" maxlength="255" autocomplete="address-line2">
                                @error('address_line_2')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-4">
                                <label for="postcode" class="form-label">Postcode</label>
                                <input id="postcode" type="text" name="postcode" value="{{ old('postcode', $business->postcode) }}"
                                       class="form-control @error('postcode') is-invalid @enderror" maxlength="20" autocomplete="postal-code">
                                @error('postcode')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-8">
                                <label for="city" class="form-label">City</label>
                                <input id="city" type="text" name="city" value="{{ old('city', $business->city) }}"
                                       class="form-control @error('city') is-invalid @enderror" maxlength="100" autocomplete="address-level2">
                                @error('city')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="state" class="form-label">State</label>
                                <input id="state" type="text" name="state" value="{{ old('state', $business->state) }}"
                                       class="form-control @error('state') is-invalid @enderror" maxlength="100" autocomplete="address-level1">
                                @error('state')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="country" class="form-label">Country</label>
                                <input id="country" type="text" name="country" value="{{ old('country', $business->country) }}"
                                       class="form-control @error('country') is-invalid @enderror" maxlength="100" autocomplete="country-name">
                                @error('country')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Save profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
