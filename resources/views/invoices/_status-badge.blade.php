@if ($invoice->isOverdue())
    <span class="badge text-bg-danger">Overdue</span>
@else
    <span @class([
        'badge',
        'text-bg-secondary' => $invoice->status === \App\Enums\InvoiceStatus::Draft,
        'text-bg-primary' => $invoice->status === \App\Enums\InvoiceStatus::Issued,
        'text-bg-success' => $invoice->status === \App\Enums\InvoiceStatus::Paid,
        'text-bg-dark' => $invoice->status === \App\Enums\InvoiceStatus::Cancelled,
    ])>{{ $invoice->status->label() }}</span>
@endif
