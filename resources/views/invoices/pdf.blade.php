{{--
    Invoice PDF (rendered by DomPDF). Standalone: no app layout, scripts, images or
    external assets. Layout uses tables because DomPDF has no flexbox or grid.
    Every value comes from the details copied onto the invoice, plus the seller's
    current business profile, and is escaped.
--}}
@php
    use App\Support\Money;

    $status = match (true) {
        $invoice->status->isCancelled() => 'CANCELLED',
        $invoice->status->isPaid() => 'PAID',
        $invoice->isOverdue() => 'OVERDUE',
        default => 'ISSUED',
    };
    $seller = $invoice->business;
    $discount = $invoice->discount_amount;
    $hasDiscount = $discount !== '0.00';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 16mm 20mm 16mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #212529; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #6c757d; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .seller { font-size: 14pt; font-weight: bold; }
        .seller-details { margin-top: 3px; font-size: 8.5pt; color: #495057; }
        .title { font-size: 20pt; font-weight: bold; letter-spacing: 1px; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid #6c757d; font-weight: bold; font-size: 9pt; }
        .status-cancelled, .status-overdue { border-color: #b02a37; color: #b02a37; }
        .status-paid { border-color: #146c43; color: #146c43; }
        .cancelled-banner { border: 2px solid #b02a37; color: #b02a37; padding: 6px 10px; margin: 10px 0 0; font-weight: bold; text-align: center; }
        .section { margin-top: 16px; }
        .label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; margin-bottom: 2px; }
        .details td { padding: 1px 0; }
        .lines { margin-top: 18px; }
        .lines th { font-size: 8pt; text-transform: uppercase; color: #6c757d; border-bottom: 1.5px solid #212529; padding: 5px 4px; text-align: left; }
        .lines th.right { text-align: right; }
        .lines .col-item { width: 50%; }
        .lines td { border-bottom: 1px solid #dee2e6; padding: 6px 4px; }
        .lines thead { display: table-header-group; }
        .lines tr { page-break-inside: avoid; }
        .totals { width: 55%; margin-left: 45%; margin-top: 10px; }
        .totals td { padding: 3px 4px; }
        .totals .grand td { border-top: 1.5px solid #212529; font-size: 11.5pt; font-weight: bold; padding-top: 6px; }
        .notes { margin-top: 20px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 55%" data-pdf-seller>
                {{-- The business's current profile (not copied onto the invoice). --}}
                <div class="seller">{{ $seller->name }}</div>
                <div class="seller-details">
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
            <td class="right">
                <div class="title">INVOICE</div>
                <div style="margin-top: 2px; font-size: 11pt; font-weight: bold;" data-pdf-number>{{ $invoice->invoice_number }}</div>
                <div style="margin-top: 6px;">
                    <span class="status status-{{ strtolower($status) }}" data-pdf-status>{{ $status }}</span>
                </div>
            </td>
        </tr>
    </table>

    @if ($invoice->status->isCancelled())
        <div class="cancelled-banner" data-pdf-cancelled>CANCELLED — this invoice is void and no payment is due.</div>
    @endif

    <table class="section">
        <tr>
            <td style="width: 55%">
                <div class="label">Bill to</div>
                <div style="font-weight: bold;" data-pdf-customer>{{ $invoice->customer_name }}</div>
                @if ($invoice->customer_company_name)
                    <div>{{ $invoice->customer_company_name }}</div>
                @endif
                @foreach ($invoice->customerAddressLines() as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($invoice->customer_email)
                    <div>{{ $invoice->customer_email }}</div>
                @endif
                @if ($invoice->customer_phone)
                    <div>{{ $invoice->customer_phone }}</div>
                @endif
            </td>
            <td>
                <table class="details">
                    <tr><td class="muted">Invoice number</td><td class="right">{{ $invoice->invoice_number }}</td></tr>
                    <tr><td class="muted">Issue date</td><td class="right" data-pdf-issue-date>{{ $invoice->issue_date->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Due date</td><td class="right" data-pdf-due-date>{{ $invoice->due_date->format('d M Y') }}</td></tr>
                    @if ($invoice->status->isPaid() && $invoice->paid_at)
                        <tr><td class="muted">Paid on</td><td class="right" data-pdf-paid-date>{{ $invoice->paid_at->format('d M Y') }}</td></tr>
                    @endif
                    <tr><td class="muted">Currency</td><td class="right" data-pdf-currency>{{ $invoice->currency_code }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th class="col-item">Item</th>
                <th class="right">Qty</th>
                <th class="right">Unit price</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr data-pdf-line>
                    <td>
                        <div style="font-weight: bold;">{{ $item->name }}</div>
                        @if ($item->description)
                            <div class="small muted">{!! nl2br(e($item->description)) !!}</div>
                        @endif
                    </td>
                    <td class="right nowrap">{{ $item->quantity }}@if ($item->unit) {{ $item->unit }}@endif</td>
                    <td class="right nowrap">{{ $item->money('unit_price') }}</td>
                    <td class="right nowrap">{{ $item->money('line_total') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr data-pdf-subtotal>
            <td>Subtotal</td>
            <td class="right nowrap">{{ $invoice->money('subtotal') }}</td>
        </tr>
        <tr data-pdf-discount>
            <td>Discount</td>
            <td class="right nowrap">{{ $hasDiscount ? '− ' : '' }}{{ Money::format($discount) }}</td>
        </tr>
        <tr data-pdf-tax>
            <td>{{ $invoice->tax_label ?: 'Tax' }} ({{ $invoice->tax_rate }}%)</td>
            <td class="right nowrap">{{ $invoice->money('tax_amount') }}</td>
        </tr>
        <tr class="grand" data-pdf-total>
            <td>Total ({{ $invoice->currency_code }})</td>
            <td class="right nowrap">{{ $invoice->money('total') }}</td>
        </tr>
    </table>

    @if ($invoice->notes)
        <div class="notes" data-pdf-notes>
            <div class="label">Notes</div>
            <div>{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif
</body>
</html>
