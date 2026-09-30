<?php

namespace Tests\Feature\Invoices;

use App\Mail\InvoiceMail;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rendering of the invoice email: subject, sender identity, Reply-To, bodies
 * and the PDF attachment.
 */
class InvoiceMailTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;
    use SendsInvoiceEmails;

    public function test_issued_and_paid_subjects(): void
    {
        $user = User::factory()->create(['name' => 'Acme Studio']);
        $invoice = $this->emailableInvoice($user);

        $this->assertSame('Invoice INV-00001 from Acme Studio', InvoiceMail::subjectFor($invoice->fresh()));

        $paid = app(InvoiceService::class)->markPaid($invoice, '2026-09-20');
        $this->assertSame('Invoice INV-00001 from Acme Studio (paid)', InvoiceMail::subjectFor($paid->fresh()));
    }

    public function test_the_sender_is_the_platform_address_with_the_business_name_and_reply_to_the_business(): void
    {
        config(['mail.from.address' => 'invoices@bizflow.test', 'app.name' => 'BizFlow']);
        $user = User::factory()->create();
        $this->businessOf($user)->update(['name' => 'Acme Studio', 'email' => 'accounts@acme.test']);
        $invoice = $this->emailableInvoice($user);

        $mail = new InvoiceMail($invoice->fresh());

        $mail->assertFrom('invoices@bizflow.test', 'Acme Studio via BizFlow');
        $mail->assertHasReplyTo('accounts@acme.test', 'Acme Studio');
        $mail->assertHasSubject('Invoice INV-00001 from Acme Studio');
    }

    public function test_there_is_no_reply_to_without_a_business_email(): void
    {
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        $mail = new InvoiceMail($invoice->fresh());

        $this->assertSame([], $mail->envelope()->replyTo);
        $mail->assertSeeInHtml('please contact');
    }

    public function test_the_bodies_summarise_the_invoice_and_the_seller(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update([
            'name' => 'Acme Studio', 'email' => 'accounts@acme.test', 'sst_number' => 'W10-1808-32000012', 'city' => 'Kuala Lumpur',
        ]);
        $invoice = $this->emailableInvoice($user, 'billing@customer.test', ['name' => 'Aisyah Rahman']);

        $mail = new InvoiceMail($invoice->fresh());

        foreach (['Dear Aisyah Rahman', 'INV-00001', 'RM 300.00', '01 Oct 2026', 'Issued', 'Acme Studio', 'SST No.: W10-1808-32000012', 'Kuala Lumpur', 'simply reply to this email'] as $text) {
            $mail->assertSeeInHtml($text);
            $mail->assertSeeInText($text);
        }
    }

    public function test_a_paid_invoice_email_thanks_for_payment(): void
    {
        $user = User::factory()->create();
        $paid = app(InvoiceService::class)->markPaid($this->emailableInvoice($user), '2026-09-20');

        $mail = new InvoiceMail($paid->fresh());

        $mail->assertSeeInHtml('Thank you for your payment');
        $mail->assertSeeInHtml('marked as paid on 20 Sep 2026');
    }

    public function test_the_html_escapes_everything_and_has_no_images_or_links(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update(['name' => '<script>alert(1)</script>', 'sst_number' => '<img src=x>']);
        $invoice = $this->emailableInvoice($user, 'billing@customer.test', ['name' => '<b>Evil</b> & Co']);

        $html = (new InvoiceMail($invoice->fresh()))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>Evil', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; &amp; Co', $html);

        // The parsed document has no links, images, scripts or anything that loads a resource.
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//a | //img | //script | //link | //iframe | //object | //b')->length);
        $this->assertSame(0, $xpath->query('//@src | //@href')->length);
    }

    public function test_the_plain_text_part_shows_values_as_typed(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update(['name' => 'Tan & Sons']);
        $invoice = $this->emailableInvoice($user);

        $mail = new InvoiceMail($invoice->fresh());

        $mail->assertSeeInText('Tan & Sons');
        $mail->assertDontSeeInText('&amp;');
    }

    public function test_the_attachment_is_the_real_invoice_pdf(): void
    {
        $transport = $this->mailTransport();
        $user = User::factory()->create();
        $invoice = $this->emailableInvoice($user);

        Mail::to('billing@customer.test')->send(new InvoiceMail($invoice->fresh()));

        $attachment = $this->pdfAttachment($this->sentEmail($transport));
        $this->assertSame('INV-00001.pdf', $attachment->getFilename());
        $this->assertSame('application/pdf', $attachment->getMediaType().'/'.$attachment->getMediaSubtype());
        $this->assertStringStartsWith('%PDF-', $attachment->getBody());
        $this->assertGreaterThan(1000, strlen($attachment->getBody()));
    }

    public function test_a_business_name_can_never_break_the_headers(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update(['name' => "Acme\r\nBcc: victim@example.com"]);
        $invoice = $this->emailableInvoice($user);

        $mail = new InvoiceMail($invoice->fresh());

        $this->assertStringNotContainsString("\n", $mail->envelope()->subject);
        $this->assertStringNotContainsString("\n", $mail->envelope()->from->name);
        $this->assertSame([], $mail->envelope()->bcc);
    }
}
