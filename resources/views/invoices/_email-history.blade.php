{{-- Email send history for the invoice (newest first). Not printed. --}}
@if ($invoice->emails->isNotEmpty())
    <div class="card shadow-sm mt-3 d-print-none" data-email-history>
        <div class="card-body">
            <h2 class="h6 text-body-secondary text-uppercase mb-3">Email history</h2>

            <ul class="list-group list-group-flush">
                @foreach ($invoice->emails as $email)
                    <li class="list-group-item px-0" data-email-row="{{ $email->status->value }}">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="text-break">
                                <span class="badge {{ $email->status->badgeClass() }}">{{ $email->status->label() }}</span>
                                <span class="ms-1">{{ $email->recipient_email }}</span>
                                <span class="small text-body-secondary">
                                    ({{ $email->recipient_source === \App\Models\InvoiceEmail::SOURCE_CUSTOMER ? 'customer’s current email' : 'email on the invoice' }})
                                </span>
                            </div>
                            <div class="small text-body-secondary text-nowrap">
                                @if ($email->sent_at)
                                    Sent {{ $email->sent_at->format('d M Y, g:i A') }}
                                @else
                                    Requested {{ $email->queued_at->format('d M Y, g:i A') }}
                                @endif
                            </div>
                        </div>

                        <div class="small text-body-secondary mt-1">
                            @if ($email->requester)
                                By {{ $email->requester->name }}.
                            @endif
                            @if ($email->attempts > 1)
                                {{ $email->attempts }} attempts.
                            @endif
                            @if (\App\Services\InvoiceEmailService::isStuck($email))
                                <span class="text-warning-emphasis">Still waiting for the queue worker; you can send it again.</span>
                            @elseif ($email->status === \App\Enums\InvoiceEmailStatus::Queued)
                                Waiting to be sent by the queue worker.
                            @endif
                        </div>

                        @if ($email->last_error && $email->status !== \App\Enums\InvoiceEmailStatus::Sent)
                            <div @class([
                                'small mt-1',
                                'text-danger' => $email->status === \App\Enums\InvoiceEmailStatus::Failed,
                                'text-body-secondary' => $email->status !== \App\Enums\InvoiceEmailStatus::Failed,
                            ])>{{ $email->last_error }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
