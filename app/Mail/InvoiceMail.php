<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invoice sent to its customer, with the invoice PDF attached.
 *
 * Sent synchronously by the SendInvoiceEmail job, never queued itself. The seller
 * is always the invoice's own business. The PDF is rendered by the existing
 * InvoicePdfService when the message is built, so it shows the invoice as it is
 * at send time.
 */
class InvoiceMail extends Mailable
{
    public function __construct(public readonly Invoice $invoice) {}

    /**
     * The subject line for an invoice in its current state.
     */
    public static function subjectFor(Invoice $invoice): string
    {
        $subject = "Invoice {$invoice->invoice_number} from ".self::oneLine($invoice->business->name);

        return $invoice->status->isPaid() ? $subject.' (paid)' : $subject;
    }

    public function envelope(): Envelope
    {
        $business = $this->invoice->business;

        return new Envelope(
            // The address stays the platform's (SPF/DKIM/DMARC); only the display name is the seller's.
            from: new Address(config('mail.from.address'), self::oneLine($business->name).' via '.config('app.name')),
            replyTo: $business->email ? [new Address($business->email, self::oneLine($business->name))] : [],
            subject: self::subjectFor($this->invoice),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoices.sent',
            text: 'emails.invoices.sent-text',
            with: ['invoice' => $this->invoice, 'seller' => $this->invoice->business],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdfs = app(InvoicePdfService::class);

        return [
            Attachment::fromData(fn () => $pdfs->render($this->invoice), $pdfs->filename($this->invoice))
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Collapse whitespace so a name can never span header lines.
     */
    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
