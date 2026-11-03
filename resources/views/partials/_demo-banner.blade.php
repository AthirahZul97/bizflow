@if (\App\Support\DemoMode::enabled())
    <div class="alert alert-warning rounded-0 border-start-0 border-end-0 mb-0 py-2 text-center small d-print-none" role="status" data-testid="demo-banner">
        <strong>Demo environment.</strong> Invoice emails are not sent, receipts are read by a demo placeholder (not real OCR),
        and the data here may be reset at any time. Don’t enter real customer or financial information.
    </div>
@endif
