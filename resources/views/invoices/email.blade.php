@extends('layouts.app')

@section('title', 'Email '.$invoice->invoice_number)

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <a href="{{ route('invoices.show', $invoice) }}">&larr; Back to invoice</a>
            <h1 class="h3 mt-2 mb-3">Email invoice {{ $invoice->invoice_number }}</h1>

            @if ($active && ! \App\Services\InvoiceEmailService::isStuck($active))
                <div class="alert alert-info" role="status">
                    This invoice is already {{ $active->status === \App\Enums\InvoiceEmailStatus::Sending ? 'being sent' : 'queued to be sent' }}
                    to {{ $active->recipient_email }}. You can send it again once that has finished.
                </div>
            @endif

            @unless ($invoice->business->email)
                <div class="alert alert-warning" role="status" data-no-reply-to>
                    Your business profile has no email address, so the customer can’t reply to this email.
                    <a href="{{ route('business.profile.edit') }}">Add one to your business profile</a>.
                </div>
            @endunless

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    @if ($options['invoice'] === null && $options['customer'] === null)
                        <p class="mb-0" data-no-recipient>
                            Neither this invoice nor the customer has an email address.
                            Add one to the customer, then send the invoice to the customer’s current email.
                        </p>
                    @else
                        <form method="POST" action="{{ route('invoices.email.store', $invoice) }}" novalidate>
                            @csrf

                            <fieldset class="mb-4">
                                <legend class="form-label fs-6 fw-semibold">Send to</legend>

                                @if ($options['invoice'] !== null)
                                    <div class="form-check mb-2">
                                        <input class="form-check-input @error('recipient') is-invalid @enderror" type="radio" name="recipient"
                                               id="recipient_invoice" value="invoice" @checked(old('recipient', 'invoice') === 'invoice')>
                                        <label class="form-check-label text-break" for="recipient_invoice">
                                            {{ $options['invoice'] }}
                                            <span class="d-block small text-body-secondary">The email on this invoice</span>
                                        </label>
                                    </div>
                                @endif

                                @if ($options['customer'] !== null)
                                    <div class="form-check mb-2">
                                        <input class="form-check-input @error('recipient') is-invalid @enderror" type="radio" name="recipient"
                                               id="recipient_customer" value="customer"
                                               @checked(old('recipient', $options['invoice'] === null ? 'customer' : null) === 'customer')>
                                        <label class="form-check-label text-break" for="recipient_customer">
                                            {{ $options['customer'] }}
                                            <span class="d-block small text-body-secondary">
                                                The customer’s current email{{ $options['invoice'] !== null ? ' (changed since this invoice was issued)' : '' }}
                                            </span>
                                        </label>
                                    </div>
                                @endif

                                @error('recipient')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </fieldset>

                            <dl class="row small mb-4">
                                <dt class="col-sm-3 fw-normal text-body-secondary">Subject</dt>
                                <dd class="col-sm-9">{{ \App\Mail\InvoiceMail::subjectFor($invoice) }}</dd>
                                <dt class="col-sm-3 fw-normal text-body-secondary">Attachment</dt>
                                <dd class="col-sm-9">{{ $invoice->invoice_number }}.pdf</dd>
                                <dt class="col-sm-3 fw-normal text-body-secondary">Reply-To</dt>
                                <dd class="col-sm-9">{{ $invoice->business->email ?? 'None' }}</dd>
                            </dl>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary">Send invoice</button>
                                <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                            <p class="form-text mb-0 mt-2">The email is sent in the background; its progress appears in the invoice’s email history.</p>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
