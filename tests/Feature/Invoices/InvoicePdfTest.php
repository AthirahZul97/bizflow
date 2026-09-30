<?php

namespace Tests\Feature\Invoices;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoicePdfService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    private function html(Invoice $invoice): string
    {
        return app(InvoicePdfService::class)->html($invoice->fresh());
    }

    private function assertPdfDownload(TestResponse $response, string $filename): void
    {
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_issued_paid_and_cancelled_invoices_download_as_pdf(): void
    {
        $user = User::factory()->create();
        $service = app(InvoiceService::class);

        $issued = $this->issuedFor($user);
        $paid = $service->markPaid($this->issuedFor($user), '2026-09-20');
        $cancelled = $service->cancel($this->issuedFor($user));

        $this->assertPdfDownload($this->actingAs($user)->get(route('invoices.pdf', $issued)), 'INV-00001.pdf');
        $this->assertPdfDownload($this->actingAs($user)->get(route('invoices.pdf', $paid)), 'INV-00002.pdf');
        $this->assertPdfDownload($this->actingAs($user)->get(route('invoices.pdf', $cancelled)), 'INV-00003.pdf');
    }

    public function test_the_invoice_page_shows_the_download_button_except_for_drafts(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor($user);
        $issued = $this->issuedFor($user);

        $this->actingAs($user)->get(route('invoices.show', $draft))
            ->assertOk()
            ->assertDontSee('Download PDF')
            ->assertDontSee(route('invoices.pdf', $draft));

        $this->actingAs($user)->get(route('invoices.show', $issued))
            ->assertOk()
            ->assertSee('Download PDF')
            ->assertSee(route('invoices.pdf', $issued));
    }

    public function test_a_draft_has_no_pdf(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->get(route('invoices.pdf', $draft))
            ->assertForbidden()
            ->assertSee('Draft invoices don’t have a PDF. Issue the invoice first.');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $invoice = $this->issuedFor(User::factory()->create());

        $this->get(route('invoices.pdf', $invoice))->assertRedirect(route('login'));
    }

    public function test_another_businesss_invoice_is_not_found_in_every_status(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $service = app(InvoiceService::class);

        $invoices = [
            $this->draftFor($owner),
            $this->issuedFor($owner),
            $service->markPaid($this->issuedFor($owner), '2026-09-20'),
            $service->cancel($this->issuedFor($owner)),
        ];

        foreach ($invoices as $invoice) {
            $this->actingAs($intruder)->get(route('invoices.pdf', $invoice))->assertNotFound();
        }
    }

    public function test_the_pdf_shows_the_invoice_details(): void
    {
        $user = User::factory()->create(['name' => 'Acme Studio']);
        $customer = $this->customerFor($user, [
            'name' => 'Aisyah Rahman',
            'company_name' => 'Rahman Trading Sdn Bhd',
            'email' => 'aisyah@example.com',
            'phone' => '+60 12-345 6789',
            'address_line_1' => '12 Jalan Bukit',
            'address_line_2' => 'Taman Melawati',
            'postcode' => '53100',
            'city' => 'Kuala Lumpur',
            'state' => 'Wilayah Persekutuan',
            'country' => 'Malaysia',
        ]);
        $invoice = $this->issuedFor($user, $customer, [
            'notes' => "Thank you for your business.\nBank transfer within 30 days.",
        ]);

        $html = $this->html($invoice);

        foreach ([
            'Acme Studio', 'INVOICE', 'INV-00001', 'ISSUED', '01 Sep 2026', '01 Oct 2026', 'MYR',
            'Aisyah Rahman', 'Rahman Trading Sdn Bhd', '12 Jalan Bukit', 'Taman Melawati',
            '53100 Kuala Lumpur, Wilayah Persekutuan', 'Malaysia', 'aisyah@example.com', '+60 12-345 6789',
            'Website Maintenance', 'Monthly maintenance', '1.00 month',
            'Thank you for your business.<br />', 'Bank transfer within 30 days.',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('CANCELLED', $html);
    }

    public function test_the_pdf_uses_the_invoice_snapshot_not_live_customer_or_product_data(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Original Customer', 'email' => 'original@example.com']);
        $product = Product::factory()->ownedBy($user)->create([
            'name' => 'Original Product', 'unit' => 'hour', 'selling_price' => '120.00',
        ]);
        $draft = $this->draftFor($user, $customer, [[
            'product_id' => $product->id, 'name' => 'Original Product', 'description' => null,
            'unit' => 'hour', 'quantity' => '2', 'unit_price' => '120.00',
        ]]);
        $invoice = app(InvoiceService::class)->issue($draft);

        $customer->update(['name' => 'Renamed Customer', 'email' => 'renamed@example.com']);
        $product->update(['name' => 'Renamed Product', 'unit' => 'day', 'selling_price' => '999.00']);

        $html = $this->html($invoice);

        $this->assertStringContainsString('Original Customer', $html);
        $this->assertStringContainsString('original@example.com', $html);
        $this->assertStringContainsString('Original Product', $html);
        $this->assertStringContainsString('2.00 hour', $html);
        $this->assertStringContainsString('RM 120.00', $html);
        $this->assertStringNotContainsString('Renamed', $html);
        $this->assertStringNotContainsString('RM 999.00', $html);
    }

    public function test_the_pdf_shows_exact_money_with_discount_and_tax(): void
    {
        $user = User::factory()->create();
        $invoice = $this->issuedFor($user, overrides: [
            'items' => [
                ['product_id' => null, 'name' => 'Design', 'description' => null, 'unit' => 'hour', 'quantity' => '2', 'unit_price' => '1250.50'],
                ['product_id' => null, 'name' => 'Hosting', 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => '0.10'],
            ],
            'discount_amount' => '1.10',
            'tax_label' => 'SST',
            'tax_rate' => '6',
        ]);
        $invoice->refresh();

        $html = $this->html($invoice);

        $this->assertSame('2501.10', $invoice->subtotal);
        $this->assertStringContainsString('RM 1,250.50', $html);
        $this->assertStringContainsString('RM 2,501.00', $html);
        $this->assertStringContainsString('RM 0.10', $html);
        $this->assertStringContainsString('RM 2,501.10', $html);
        $this->assertStringContainsString('− RM 1.10', $html);
        $this->assertStringContainsString('SST (6.00%)', $html);
        $this->assertStringContainsString($invoice->money('tax_amount'), $html);
        $this->assertStringContainsString('Total (MYR)', $html);
        $this->assertStringContainsString($invoice->money('total'), $html);
        $this->assertSame('RM 2,650.00', $invoice->money('total'));
    }

    public function test_zero_discount_and_tax_rows_are_still_shown(): void
    {
        $invoice = $this->issuedFor(User::factory()->create());

        $html = $this->html($invoice);

        $this->assertMatchesRegularExpression('/data-pdf-discount>\s*<td>Discount<\/td>\s*<td[^>]*>RM 0\.00<\/td>/', $html);
        $this->assertMatchesRegularExpression('/data-pdf-tax>\s*<td>Tax \(0\.00%\)<\/td>\s*<td[^>]*>RM 0\.00<\/td>/', $html);
        $this->assertMatchesRegularExpression('/data-pdf-total>\s*<td>Total \(MYR\)<\/td>\s*<td[^>]*>RM 300\.00<\/td>/', $html);
    }

    public function test_status_labels_for_overdue_paid_and_cancelled(): void
    {
        $user = User::factory()->create();
        $service = app(InvoiceService::class);

        $overdue = $this->issuedFor($user, overrides: ['due_date' => '2026-09-10']);
        $paid = $service->markPaid($this->issuedFor($user), '2026-09-20');
        $cancelled = $service->cancel($this->issuedFor($user));

        $this->assertStringContainsString('data-pdf-status>OVERDUE<', $this->html($overdue));

        $paidHtml = $this->html($paid);
        $this->assertStringContainsString('data-pdf-status>PAID<', $paidHtml);
        $this->assertStringContainsString('20 Sep 2026', $paidHtml);

        $cancelledHtml = $this->html($cancelled);
        $this->assertStringContainsString('data-pdf-status>CANCELLED<', $cancelledHtml);
        $this->assertStringContainsString('data-pdf-cancelled', $cancelledHtml);
        $this->assertStringContainsString('no payment is due', $cancelledHtml);
    }

    public function test_user_content_is_escaped_and_no_images_or_external_resources_are_rendered(): void
    {
        $user = User::factory()->create(['name' => '<b>Seller</b>']);
        $customer = $this->customerFor($user, [
            'name' => '<script>alert(1)</script>',
            'company_name' => '<img src=x onerror=alert(2)>',
            'address_line_1' => '<svg onload=alert(3)>',
        ]);
        $invoice = $this->issuedFor($user, $customer, [
            'items' => [[
                'product_id' => null,
                'name' => '<img src="http://example.com/x.png">',
                'description' => "<link rel=stylesheet href=//evil.test/x.css>\nsecond line",
                'unit' => '<i>u</i>',
                'quantity' => '1',
                'unit_price' => '10.00',
            ]],
            'tax_label' => '<u>Tax</u>',
            'notes' => "<iframe src=//evil.test></iframe>\n<?php echo 1; ?>",
        ]);

        $html = $this->html($invoice);

        foreach (['<script', '<img', '<svg', '<link', '<iframe', '<?php', '<b>Seller', '<i>u', '<u>Tax'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }

        // The parsed document has no elements or attributes that could load anything.
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//img | //svg | //script | //link | //iframe | //object | //embed | //b | //i | //u')->length);
        $this->assertSame(0, $xpath->query('//@src | //@href')->length);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Seller&lt;/b&gt;', $html);
        $this->assertStringContainsString('<br />', $html);

        $this->assertPdfDownload($this->actingAs($user)->get(route('invoices.pdf', $invoice)), 'INV-00001.pdf');
    }

    public function test_downloading_does_not_change_the_invoice_and_only_reads(): void
    {
        $user = User::factory()->create();
        $invoice = $this->issuedFor($user);
        $before = $invoice->fresh()->getAttributes();
        $itemsBefore = $invoice->items()->get()->map->getAttributes()->all();
        $customerBefore = $invoice->customer()->first()->getAttributes();

        $this->travel(5)->minutes();
        DB::enableQueryLog();

        $this->assertPdfDownload($this->actingAs($user)->get(route('invoices.pdf', $invoice)), 'INV-00001.pdf');

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
        }
        $this->assertSame($before, $invoice->fresh()->getAttributes());
        $this->assertSame($itemsBefore, $invoice->items()->get()->map->getAttributes()->all());
        $this->assertSame($customerBefore, $invoice->customer()->first()->getAttributes());
    }

    public function test_the_pdf_and_invoice_page_show_the_business_profile_as_seller(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update([
            'name' => 'Acme Studio Sdn Bhd',
            'registration_number' => '202001234567 (1234567-A)',
            'sst_number' => 'W10-1808-32000012',
            'email' => 'billing@acme.test',
            'phone' => '+60 3-1234 5678',
            'address_line_1' => '12 Jalan Bukit',
            'address_line_2' => 'Taman Melawati',
            'city' => 'Kuala Lumpur',
            'state' => 'Wilayah Persekutuan',
            'postcode' => '53100',
            'country' => 'Malaysia',
        ]);
        $invoice = $this->issuedFor($user);

        $expected = [
            'Acme Studio Sdn Bhd', '12 Jalan Bukit', 'Taman Melawati', '53100 Kuala Lumpur, Wilayah Persekutuan', 'Malaysia',
            'Registration No.: 202001234567 (1234567-A)', 'SST No.: W10-1808-32000012', 'billing@acme.test · +60 3-1234 5678',
        ];

        $seller = $this->sellerBlock($this->html($invoice));
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $seller);
        }

        $page = $this->actingAs($user)->get(route('invoices.show', $invoice))->assertOk();
        foreach ($expected as $text) {
            $page->assertSee($text);
        }

        $this->assertPdfDownload($this->actingAs($user)->get(route('invoices.pdf', $invoice)), 'INV-00001.pdf');
    }

    public function test_a_minimal_profile_shows_only_the_business_name(): void
    {
        $user = User::factory()->create(['name' => 'Solo Trader']);
        $invoice = $this->issuedFor($user);

        $seller = $this->sellerBlock($this->html($invoice));

        $this->assertStringContainsString('Solo Trader', $seller);
        $this->assertStringNotContainsString('Registration No.', $seller);
        $this->assertStringNotContainsString('SST No.', $seller);
        $this->assertStringNotContainsString('·', $seller);
    }

    public function test_the_seller_is_the_businesss_current_profile_while_the_customer_stays_a_snapshot(): void
    {
        $user = User::factory()->create(['name' => 'Old Trading Name']);
        $customer = $this->customerFor($user, ['name' => 'Original Customer']);
        $invoice = $this->issuedFor($user, $customer);

        $this->businessOf($user)->update(['name' => 'New Trading Name', 'sst_number' => 'W10-NEW']);
        $customer->update(['name' => 'Renamed Customer']);

        $html = $this->html($invoice);

        // Seller details are read live (not copied onto invoices yet).
        $this->assertStringContainsString('New Trading Name', $html);
        $this->assertStringContainsString('SST No.: W10-NEW', $html);
        $this->assertStringNotContainsString('Old Trading Name', $html);
        // Customer details remain the copy taken when the invoice was issued.
        $this->assertStringContainsString('Original Customer', $html);
        $this->assertStringNotContainsString('Renamed Customer', $html);
    }

    public function test_business_profile_values_are_escaped_in_the_pdf(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update([
            'name' => '<script>alert(1)</script>',
            'sst_number' => '<img src=x onerror=alert(2)>',
            'address_line_1' => '<b>Bold street</b>',
        ]);
        $invoice = $this->issuedFor($user);

        $html = $this->html($invoice);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>Bold', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('SST No.: &lt;img src=x onerror=alert(2)&gt;', $html);
    }

    /**
     * The seller cell of the PDF header.
     */
    private function sellerBlock(string $html): string
    {
        $this->assertSame(1, preg_match('/<td[^>]*data-pdf-seller>(.*?)<\/td>/s', $html, $match));

        return $match[1];
    }

    public function test_a_long_invoice_renders_across_several_pages(): void
    {
        $user = User::factory()->create();
        $items = [];
        for ($i = 1; $i <= 100; $i++) {
            $items[] = [
                'product_id' => null, 'name' => "Line item {$i}", 'description' => "Description for line {$i}",
                'unit' => 'pcs', 'quantity' => '1', 'unit_price' => '10.00',
            ];
        }
        $invoice = app(InvoiceService::class)->issue($this->draftFor($user, items: $items));

        $this->assertSame(100, substr_count($this->html($invoice), 'data-pdf-line'));

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $this->assertPdfDownload($response, 'INV-00001.pdf');
        $pages = preg_match_all('/\/Type\s*\/Page(?!s)/', $response->getContent());
        $this->assertGreaterThan(1, $pages);
    }
}
