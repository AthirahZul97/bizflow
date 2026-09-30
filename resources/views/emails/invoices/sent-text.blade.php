{{-- Plain-text part (text/plain, never rendered as HTML), so values are printed as-is: escaping would show &amp; etc. --}}
Dear {!! $invoice->customer_name !!},

@if ($invoice->status->isPaid())
Thank you for your payment. Attached is a copy of invoice {!! $invoice->invoice_number !!} from {!! $seller->name !!}, marked as paid{!! $invoice->paid_at ? ' on '.$invoice->paid_at->format('d M Y') : '' !!}.
@else
Please find attached invoice {!! $invoice->invoice_number !!} from {!! $seller->name !!} for {!! $invoice->money('total') !!}, due on {!! $invoice->due_date->format('d M Y') !!}.
@endif

Invoice number: {!! $invoice->invoice_number !!}
Issue date: {!! $invoice->issue_date->format('d M Y') !!}
Due date: {!! $invoice->due_date->format('d M Y') !!}
Status: {!! $invoice->status->label() !!}
Total ({!! $invoice->currency_code !!}): {!! $invoice->money('total') !!}

The invoice is attached as a PDF ({!! $invoice->invoice_number !!}.pdf).
@if ($seller->email)
If you have any questions, simply reply to this email.
@else
If you have any questions, please contact {!! $seller->name !!}.
@endif

--
{!! $seller->name !!}
@foreach ($seller->addressLines() as $line)
{!! $line !!}
@endforeach
@if ($seller->registration_number)
Registration No.: {!! $seller->registration_number !!}
@endif
@if ($seller->sst_number)
SST No.: {!! $seller->sst_number !!}
@endif
@if ($seller->email || $seller->phone)
{!! collect([$seller->email, $seller->phone])->filter()->implode(' · ') !!}
@endif

Sent with {!! config('app.name') !!}
