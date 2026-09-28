@csrf

<h2 class="h6 text-body-secondary text-uppercase mb-3">Contact</h2>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label for="name" class="form-label">Name <span class="text-danger" aria-hidden="true">*</span></label>
        <input id="name" type="text" name="name" value="{{ old('name', $customer->name) }}"
               class="form-control @error('name') is-invalid @enderror" required maxlength="255" autofocus>
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="company_name" class="form-label">Company</label>
        <input id="company_name" type="text" name="company_name" value="{{ old('company_name', $customer->company_name) }}"
               class="form-control @error('company_name') is-invalid @enderror" maxlength="255" autocomplete="organization">
        @error('company_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="email" class="form-label">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email', $customer->email) }}"
               class="form-control @error('email') is-invalid @enderror" maxlength="255" autocomplete="off">
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="phone" class="form-label">Phone</label>
        <input id="phone" type="tel" name="phone" value="{{ old('phone', $customer->phone) }}"
               class="form-control @error('phone') is-invalid @enderror" maxlength="30" autocomplete="off">
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-3">Address</h2>

<div class="row g-3 mb-4">
    <div class="col-12">
        <label for="address_line_1" class="form-label">Address line 1</label>
        <input id="address_line_1" type="text" name="address_line_1" value="{{ old('address_line_1', $customer->address_line_1) }}"
               class="form-control @error('address_line_1') is-invalid @enderror" maxlength="255">
        @error('address_line_1')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="address_line_2" class="form-label">Address line 2</label>
        <input id="address_line_2" type="text" name="address_line_2" value="{{ old('address_line_2', $customer->address_line_2) }}"
               class="form-control @error('address_line_2') is-invalid @enderror" maxlength="255">
        @error('address_line_2')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-5">
        <label for="city" class="form-label">City</label>
        <input id="city" type="text" name="city" value="{{ old('city', $customer->city) }}"
               class="form-control @error('city') is-invalid @enderror" maxlength="100">
        @error('city')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="state" class="form-label">State</label>
        <input id="state" type="text" name="state" value="{{ old('state', $customer->state) }}"
               class="form-control @error('state') is-invalid @enderror" maxlength="100">
        @error('state')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-3">
        <label for="postcode" class="form-label">Postcode</label>
        <input id="postcode" type="text" name="postcode" value="{{ old('postcode', $customer->postcode) }}"
               class="form-control @error('postcode') is-invalid @enderror" maxlength="20">
        @error('postcode')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="country" class="form-label">Country</label>
        <input id="country" type="text" name="country" value="{{ old('country', $customer->country) }}"
               class="form-control @error('country') is-invalid @enderror" maxlength="100">
        @error('country')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<h2 class="h6 text-body-secondary text-uppercase mb-3">Notes</h2>

<div class="mb-4">
    <label for="notes" class="form-label visually-hidden">Notes</label>
    <textarea id="notes" name="notes" rows="4" maxlength="5000"
              class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $customer->notes) }}</textarea>
    @error('notes')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
