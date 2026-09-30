<?php

namespace Tests\Feature\Invoices;

use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\User;
use App\Services\InvoiceEmailService;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\ScriptedTransport;

/**
 * Helpers for invoice email tests. Use together with CreatesInvoices.
 */
trait SendsInvoiceEmails
{
    /**
     * Send mail through a scripted transport (see ScriptedTransport).
     */
    protected function mailTransport(array $steps = []): ScriptedTransport
    {
        $transport = new ScriptedTransport($steps);
        Mail::extend('scripted', fn () => $transport);
        config(['mail.mailers.scripted' => ['transport' => 'scripted'], 'mail.default' => 'scripted']);
        Mail::purge('scripted');

        return $transport;
    }

    /**
     * An issued invoice whose customer has the given email.
     */
    protected function emailableInvoice(User $user, string $email = 'billing@customer.test', array $customer = []): Invoice
    {
        return $this->issuedFor($user, $this->customerFor($user, ['email' => $email] + $customer));
    }

    protected function requestSend(User $user, Invoice $invoice, string $source = InvoiceEmail::SOURCE_INVOICE): InvoiceEmail
    {
        return app(InvoiceEmailService::class)->queue($this->businessOf($user), $invoice, $user, $source);
    }

    /**
     * Run the job's handle() for a row, as a worker would.
     */
    protected function runJob(InvoiceEmail|int $email): void
    {
        (new SendInvoiceEmail($email instanceof InvoiceEmail ? $email->getKey() : $email))
            ->handle(app(InvoiceEmailService::class));
    }

    protected function sentEmail(ScriptedTransport $transport, int $index = 0): Email
    {
        $message = $transport->sent[$index]->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }

    protected function pdfAttachment(Email $email): DataPart
    {
        $this->assertCount(1, $email->getAttachments());

        return $email->getAttachments()[0];
    }
}
