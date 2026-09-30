{{--
    Invoice email (HTML). Inline styles only: no images, no remote resources, no links,
    no tracking. Every value is escaped. The seller is the invoice's own business.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }}</title>
</head>
<body style="margin: 0; padding: 24px 12px; background: #f5f6f8; font-family: Arial, Helvetica, sans-serif; color: #212529; font-size: 15px; line-height: 1.5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #dee2e6; border-radius: 6px;">
        <tr>
            <td style="padding: 24px;">
                <p style="margin: 0 0 16px;">Dear {{ $invoice->customer_name }},</p>

                @if ($invoice->status->isPaid())
                    <p style="margin: 0 0 16px;" data-email-intro>
                        Thank you for your payment. Attached is a copy of invoice {{ $invoice->invoice_number }}
                        from {{ $seller->name }}, marked as paid{{ $invoice->paid_at ? ' on '.$invoice->paid_at->format('d M Y') : '' }}.
                    </p>
                @else
                    <p style="margin: 0 0 16px;" data-email-intro>
                        Please find attached invoice {{ $invoice->invoice_number }} from {{ $seller->name }}
                        for {{ $invoice->money('total') }}, due on {{ $invoice->due_date->format('d M Y') }}.
                    </p>
                @endif

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 0 0 16px; border-top: 1px solid #dee2e6;" data-email-summary>
                    <tr><td style="padding: 6px 0; color: #6c757d;">Invoice number</td><td style="padding: 6px 0; text-align: right;">{{ $invoice->invoice_number }}</td></tr>
                    <tr><td style="padding: 6px 0; color: #6c757d;">Issue date</td><td style="padding: 6px 0; text-align: right;">{{ $invoice->issue_date->format('d M Y') }}</td></tr>
                    <tr><td style="padding: 6px 0; color: #6c757d;">Due date</td><td style="padding: 6px 0; text-align: right;">{{ $invoice->due_date->format('d M Y') }}</td></tr>
                    <tr><td style="padding: 6px 0; color: #6c757d;">Status</td><td style="padding: 6px 0; text-align: right;">{{ $invoice->status->label() }}</td></tr>
                    <tr><td style="padding: 8px 0; border-top: 1px solid #dee2e6; font-weight: bold;">Total ({{ $invoice->currency_code }})</td><td style="padding: 8px 0; border-top: 1px solid #dee2e6; text-align: right; font-weight: bold;">{{ $invoice->money('total') }}</td></tr>
                </table>

                <p style="margin: 0 0 16px;">
                    The invoice is attached as a PDF ({{ $invoice->invoice_number }}.pdf).
                    @if ($seller->email)
                        If you have any questions, simply reply to this email.
                    @else
                        If you have any questions, please contact {{ $seller->name }}.
                    @endif
                </p>

                <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #dee2e6; font-size: 13px; color: #495057;" data-email-seller>
                    <div style="font-weight: bold; color: #212529;">{{ $seller->name }}</div>
                    @foreach ($seller->addressLines() as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    @if ($seller->registration_number)
                        <div>Registration No.: {{ $seller->registration_number }}</div>
                    @endif
                    @if ($seller->sst_number)
                        <div>SST No.: {{ $seller->sst_number }}</div>
                    @endif
                    @if ($seller->email || $seller->phone)
                        <div>{{ collect([$seller->email, $seller->phone])->filter()->implode(' · ') }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>
    <p style="max-width: 560px; margin: 12px auto 0; font-size: 12px; color: #6c757d; text-align: center;">Sent with {{ config('app.name') }}</p>
</body>
</html>
