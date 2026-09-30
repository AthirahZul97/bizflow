<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceManagementTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    public function test_index_lists_only_the_users_own_invoices(): void
    {
        $user = User::factory()->create();
        $this->draftFor($user, $this->customerFor($user, ['name' => 'My Customer']));
        $this->draftFor($other = User::factory()->create(), $this->customerFor($other, ['name' => 'Their Customer']));

        $this->actingAs($user)->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('My Customer')
            ->assertDontSee('Their Customer');
    }

    public function test_create_form_offers_own_customers_and_only_active_own_products(): void
    {
        $user = User::factory()->create();
        $this->customerFor($user, ['name' => 'Own Customer']);
        $this->customerFor(User::factory()->create(), ['name' => 'Foreign Customer']);
        Product::factory()->ownedBy($user)->create(['name' => 'Active Service']);
        Product::factory()->ownedBy($user)->inactive()->create(['name' => 'Retired Service']);
        Product::factory()->create(['name' => 'Foreign Product']);

        $this->actingAs($user)->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('Own Customer')
            ->assertDontSee('Foreign Customer')
            ->assertSee('Active Service')
            ->assertDontSee('Retired Service')
            ->assertDontSee('Foreign Product')
            ->assertSee('value="2026-09-28"', false)   // issue date: today
            ->assertSee('value="2026-10-28"', false);  // due date: today + 30 days
    }

    public function test_users_can_create_a_draft(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);

        $response = $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($customer, overrides: [
            'discount_amount' => '50',
            'tax_label' => 'SST',
            'tax_rate' => '8',
            'notes' => 'Bank transfer to Maybank 1234.',
        ]));

        $invoice = Invoice::sole();
        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('status', 'Draft invoice saved.');

        $this->assertTrue($this->ownerOf($invoice)->is($user));
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->invoice_sequence);
        $this->assertSame('MYR', $invoice->currency_code);
        $this->assertSame('300.00', $invoice->subtotal);
        $this->assertSame('50.00', $invoice->discount_amount);
        $this->assertSame('20.00', $invoice->tax_amount);   // 8% of 250.00
        $this->assertSame('270.00', $invoice->total);
        $this->assertSame('SST', $invoice->tax_label);
        $this->assertSame('2026-09-01', $invoice->issue_date->toDateString());

        $this->get(route('invoices.show', $invoice))
            ->assertSee('Draft invoice')
            ->assertSee('SST (8.00%)')
            ->assertSee('RM 270.00')
            ->assertSee('Bank transfer to Maybank 1234.');
    }

    public function test_customer_details_are_copied_onto_the_invoice(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, [
            'name' => 'Nur Aina', 'company_name' => 'Aina Trading', 'email' => 'aina@example.com',
            'phone' => '+60 12-345 6789', 'address_line_1' => '12 Jalan Mawar', 'address_line_2' => 'Taman Melati',
            'city' => 'Kuala Lumpur', 'state' => 'WP', 'postcode' => '53100', 'country' => 'Malaysia',
        ]);

        $invoice = $this->draftFor($user, $customer);

        $this->assertSame(
            ['Nur Aina', 'Aina Trading', 'aina@example.com', '+60 12-345 6789', '12 Jalan Mawar', 'Taman Melati', 'Kuala Lumpur', 'WP', '53100', 'Malaysia'],
            [$invoice->customer_name, $invoice->customer_company_name, $invoice->customer_email, $invoice->customer_phone,
                $invoice->customer_address_line_1, $invoice->customer_address_line_2, $invoice->customer_city,
                $invoice->customer_state, $invoice->customer_postcode, $invoice->customer_country],
        );
    }

    public function test_customer_changes_reach_a_draft_only_when_it_is_saved_again(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Old Name']);
        $invoice = $this->draftFor($user, $customer);

        $customer->update(['name' => 'New Name']);
        $this->assertSame('Old Name', $invoice->fresh()->customer_name);

        $this->actingAs($user)->put(route('invoices.update', $invoice), $this->invoicePayload($customer))
            ->assertSessionHasNoErrors();
        $this->assertSame('New Name', $invoice->fresh()->customer_name);
    }

    public function test_selected_product_fills_blank_line_fields_but_never_its_cost(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->service()->create([
            'name' => 'Website Development', 'description' => 'Five-page site', 'unit' => 'project',
            'selling_price' => '1500.00', 'cost_price' => '777.77',
        ]);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), [
            ['product_id' => $product->id, 'name' => '', 'description' => '', 'unit' => '', 'quantity' => '2', 'unit_price' => ''],
        ]))->assertSessionHasNoErrors();

        $item = InvoiceItem::sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('Website Development', $item->name);
        $this->assertSame('Five-page site', $item->description);
        $this->assertSame('project', $item->unit);
        $this->assertSame('1500.00', $item->unit_price);
        $this->assertSame('3000.00', $item->line_total);
        $this->assertArrayNotHasKey('cost_price', $item->getAttributes());

        $this->get(route('invoices.show', $item->invoice_id))->assertDontSee('777.77');
    }

    public function test_typed_line_values_override_the_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Catalogue Name', 'selling_price' => '1500.00']);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), [
            ['product_id' => $product->id, 'name' => 'Custom Name', 'quantity' => '1', 'unit_price' => '1200.00'],
        ]))->assertSessionHasNoErrors();

        $item = InvoiceItem::sole();
        $this->assertSame('Custom Name', $item->name);
        $this->assertSame('1200.00', $item->unit_price);
        $this->assertSame('1200.00', $item->line_total);
    }

    public function test_later_product_changes_do_not_change_invoice_lines(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Website Development', 'selling_price' => '1500.00']);
        $invoice = $this->draftFor($user, items: [['product_id' => $product->id, 'quantity' => '1']]);

        $product->update(['name' => 'Website Development v2', 'selling_price' => '2000.00']);

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertSee('Website Development')
            ->assertDontSee('Website Development v2')
            ->assertSee('RM 1,500.00')
            ->assertDontSee('RM 2,000.00');
    }

    public function test_manual_and_product_lines_can_be_mixed_and_positions_follow_submitted_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Laptop Stand', 'selling_price' => '89.90']);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), [
            ['name' => 'Consultation', 'quantity' => '1.5', 'unit_price' => '200.00'],
            ['product_id' => $product->id, 'quantity' => '3'],
            ['name' => 'Delivery', 'quantity' => '1', 'unit_price' => '15.00'],
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(
            [[1, 'Consultation', null, '300.00'], [2, 'Laptop Stand', $product->id, '269.70'], [3, 'Delivery', null, '15.00']],
            $invoice->items->map(fn ($i) => [$i->position, $i->name, $i->product_id, $i->line_total])->all(),
        );
        $this->assertSame('584.70', $invoice->total);
    }

    public function test_completely_blank_rows_are_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), [
            ['product_id' => '', 'name' => '', 'description' => '', 'unit' => '', 'quantity' => '', 'unit_price' => ''],
            ['name' => 'Only Line', 'quantity' => '1', 'unit_price' => '10'],
            ['product_id' => '', 'name' => '', 'quantity' => '', 'unit_price' => ''],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['Only Line'], InvoiceItem::pluck('name')->all());
        $this->assertSame(1, InvoiceItem::sole()->position);
    }

    public function test_creating_a_draft_does_not_delete_lines_first(): void
    {
        // Deleting a new invoice's (non-existent) lines took a MySQL gap lock that made
        // concurrent draft creation deadlock; only edits of an existing draft delete lines.
        $user = User::factory()->create();
        $customer = $this->customerFor($user);

        DB::enableQueryLog();
        $invoice = $this->draftFor($user, $customer);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_starts_with(strtolower($sql), 'delete'))));
        $this->assertCount(1, $invoice->items);
    }

    public function test_editing_a_draft_replaces_its_lines_and_recalculates(): void
    {
        $user = User::factory()->create();
        $invoice = $this->draftFor($user);

        $response = $this->actingAs($user)->put(route('invoices.update', $invoice), $this->invoicePayload($invoice->customer, [
            ['name' => 'New A', 'quantity' => '2', 'unit_price' => '10.00'],
            ['name' => 'New B', 'quantity' => '1', 'unit_price' => '5.00'],
        ]));

        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('status', 'Draft invoice updated.');
        $invoice->refresh();
        $this->assertSame(['New A', 'New B'], $invoice->items->pluck('name')->all());
        $this->assertSame([1, 2], $invoice->items->pluck('position')->all());
        $this->assertSame('25.00', $invoice->total);
        $this->assertSame(2, InvoiceItem::count());
    }

    public function test_edit_form_shows_the_saved_lines(): void
    {
        $user = User::factory()->create();
        $invoice = $this->draftFor($user);

        $this->actingAs($user)->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('value="Website Maintenance"', false)
            ->assertSee('value="300.00"', false);
    }

    public function test_a_draft_may_keep_a_product_that_was_deactivated_after_it_was_added(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->create(['name' => 'Legacy Plan']);
        $invoice = $this->draftFor($user, items: [['product_id' => $product->id, 'quantity' => '1']]);
        $product->update(['is_active' => false]);

        $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertSee('Legacy Plan');

        $this->actingAs($user)->put(route('invoices.update', $invoice), $this->invoicePayload($invoice->customer, [
            ['product_id' => $product->id, 'quantity' => '2'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2.00', $invoice->fresh()->items->sole()->quantity);
    }

    public function test_an_inactive_product_cannot_be_newly_added(): void
    {
        $user = User::factory()->create();
        $inactive = Product::factory()->ownedBy($user)->inactive()->create();
        $invoice = $this->draftFor($user);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($invoice->customer, [
            ['product_id' => $inactive->id, 'quantity' => '1'],
        ]))->assertSessionHasErrors(['items.0.product_id' => 'This product is inactive and cannot be added to an invoice.']);

        $this->actingAs($user)->put(route('invoices.update', $invoice), $this->invoicePayload($invoice->customer, [
            ['product_id' => $inactive->id, 'quantity' => '1'],
        ]))->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(1, Invoice::count());
        $this->assertNull($invoice->fresh()->items->sole()->product_id);
    }

    public function test_another_users_customer_is_rejected(): void
    {
        $user = User::factory()->create();
        $foreignCustomer = $this->customerFor(User::factory()->create());

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($foreignCustomer))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Invoice::count());
    }

    public function test_another_users_product_is_rejected(): void
    {
        $user = User::factory()->create();
        $foreignProduct = Product::factory()->create(['selling_price' => '10.00']);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), [
            ['product_id' => $foreignProduct->id, 'name' => 'Sneaky', 'quantity' => '1', 'unit_price' => '1'],
        ]))->assertSessionHasErrors(['items.0.product_id' => 'The selected product is invalid.']);

        $this->assertSame(0, Invoice::count());
    }

    public function test_server_controlled_fields_cannot_be_tampered_with(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Real Customer']);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($customer, [
            ['name' => 'Line', 'quantity' => '2', 'unit_price' => '10.00', 'line_total' => '99999.00', 'position' => '7'],
            ['name' => 'Line 2', 'quantity' => '1', 'unit_price' => '1.00', 'line_total' => '0.01', 'position' => '7'],
        ], [
            'user_id' => $victim->id,
            'business_id' => $this->businessOf($victim)->id,
            'created_by' => $victim->id,
            'status' => 'paid',
            'invoice_number' => 'INV-99999',
            'invoice_sequence' => 99999,
            'currency_code' => 'USD',
            'customer_name' => 'Forged Name',
            'subtotal' => '1.00',
            'tax_amount' => '1.00',
            'total' => '1.00',
            'issued_at' => '2020-01-01 00:00:00',
            'paid_at' => '2020-01-01',
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame($this->businessOf($user)->id, $invoice->business_id);
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->invoice_sequence);
        $this->assertSame('MYR', $invoice->currency_code);
        $this->assertSame('Real Customer', $invoice->customer_name);
        $this->assertSame('21.00', $invoice->subtotal);
        $this->assertSame('21.00', $invoice->total);
        $this->assertNull($invoice->issued_at);
        $this->assertNull($invoice->paid_at);
        $this->assertSame([['20.00', 1], ['1.00', 2]], $invoice->items->map(fn ($i) => [$i->line_total, $i->position])->all());
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing customer' => ['customer_id', '', 'customer_id'],
            'non-numeric customer' => ['customer_id', 'abc', 'customer_id'],
            'unknown customer' => ['customer_id', '999999', 'customer_id'],
            'missing issue date' => ['issue_date', '', 'issue_date'],
            'invalid issue date' => ['issue_date', '2026-13-01', 'issue_date'],
            'wrong date format' => ['issue_date', '01/09/2026', 'issue_date'],
            'due before issue' => ['due_date', '2026-08-31', 'due_date'],
            'missing due date' => ['due_date', '', 'due_date'],
            'negative discount' => ['discount_amount', '-1', 'discount_amount'],
            'discount three decimals' => ['discount_amount', '1.234', 'discount_amount'],
            'discount scientific' => ['discount_amount', '1e2', 'discount_amount'],
            'discount above subtotal' => ['discount_amount', '300.01', 'discount_amount'],
            'negative tax rate' => ['tax_rate', '-1', 'tax_rate'],
            'tax rate above 100' => ['tax_rate', '100.01', 'tax_rate'],
            'tax rate text' => ['tax_rate', 'six', 'tax_rate'],
            'tax label too long' => ['tax_label', str_repeat('a', 31), 'tax_label'],
            'notes too long' => ['notes', str_repeat('a', 5001), 'notes'],
            'items not an array' => ['items', 'none', 'items'],
            'zero quantity' => ['items.0.quantity', '0', 'items.0.quantity'],
            'negative quantity' => ['items.0.quantity', '-1', 'items.0.quantity'],
            'quantity three decimals' => ['items.0.quantity', '1.234', 'items.0.quantity'],
            'quantity scientific' => ['items.0.quantity', '1e3', 'items.0.quantity'],
            'quantity with comma' => ['items.0.quantity', '1,500', 'items.0.quantity'],
            'quantity over maximum' => ['items.0.quantity', '100000000', 'items.0.quantity'],
            'missing quantity' => ['items.0.quantity', '', 'items.0.quantity'],
            'negative price' => ['items.0.unit_price', '-0.01', 'items.0.unit_price'],
            'price three decimals' => ['items.0.unit_price', '12.345', 'items.0.unit_price'],
            'price scientific' => ['items.0.unit_price', '1e3', 'items.0.unit_price'],
            'price with comma' => ['items.0.unit_price', '1,500.00', 'items.0.unit_price'],
            'price over maximum' => ['items.0.unit_price', '10000000000', 'items.0.unit_price'],
            'manual line without price' => ['items.0.unit_price', '', 'items.0.unit_price'],
            'manual line without name' => ['items.0.name', '', 'items.0.name'],
            'name too long' => ['items.0.name', str_repeat('a', 256), 'items.0.name'],
            'description too long' => ['items.0.description', str_repeat('a', 2001), 'items.0.description'],
            'unit too long' => ['items.0.unit', str_repeat('a', 31), 'items.0.unit'],
            'non-numeric product' => ['items.0.product_id', 'abc', 'items.0.product_id'],
            'unknown product' => ['items.0.product_id', '999999', 'items.0.product_id'],
            'line total overflow' => ['items.0.quantity', '99999999.99', 'items.0.unit_price'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(string $path, mixed $value, string $errorKey): void
    {
        $user = User::factory()->create();
        $payload = $this->invoicePayload($this->customerFor($user));
        data_set($payload, $path, $value);

        if ($path === 'items.0.quantity' && $value === '99999999.99') {
            data_set($payload, 'items.0.unit_price', '9999999999.99');
        }

        $response = $this->actingAs($user)
            ->from(route('invoices.create'))
            ->post(route('invoices.store'), $payload);

        $response->assertRedirect(route('invoices.create'));
        $response->assertSessionHasErrors($errorKey);
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_needs_at_least_one_line(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($customer, []))
            ->assertSessionHasErrors(['items' => 'Add at least one line to the invoice.']);

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($customer, [
            ['product_id' => '', 'name' => '', 'quantity' => '', 'unit_price' => ''],
        ]))->assertSessionHasErrors('items');

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_invoice_can_have_at_most_one_hundred_lines(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);
        $line = ['name' => 'Line', 'quantity' => '1', 'unit_price' => '1'];

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($customer, array_fill(0, 101, $line)))
            ->assertSessionHasErrors('items');
        $this->assertSame(0, Invoice::count());

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($customer, array_fill(0, 100, $line)))
            ->assertSessionHasNoErrors();
        $this->assertSame('100.00', Invoice::sole()->total);
    }

    public function test_due_date_may_equal_issue_date_and_past_issue_dates_are_allowed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), overrides: [
            'issue_date' => '2025-01-15',
            'due_date' => '2025-01-15',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2025-01-15', Invoice::sole()->due_date->toDateString());
    }

    public function test_large_amounts_are_stored_exactly(): void
    {
        // The full 15-digit DECIMAL(15,2) maximum is verified against MySQL: the in-memory
        // SQLite test database stores decimals as floats, which PHP renders with 14 digits.
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($user), [
            ['name' => 'Big', 'quantity' => '10', 'unit_price' => '9999999999.99'],
            ['name' => 'Small', 'quantity' => '0.5', 'unit_price' => '0.03'],
        ]))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('9999999999.99', $invoice->items->first()->unit_price);
        $this->assertSame('99999999999.90', $invoice->items->first()->line_total);
        $this->assertSame('0.02', $invoice->items->last()->line_total); // 0.015 rounds half up
        $this->assertSame('99999999999.92', $invoice->total);
    }

    public function test_drafts_can_be_deleted_with_their_lines_and_the_confirmation_page_does_not_delete(): void
    {
        $user = User::factory()->create();
        $invoice = $this->draftFor($user);

        $this->actingAs($user)->get(route('invoices.delete', $invoice))
            ->assertOk()
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('name="_token"', false);
        $this->assertModelExists($invoice);

        $this->actingAs($user)->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('status', 'Draft invoice deleted.');

        $this->assertModelMissing($invoice);
        $this->assertSame(0, InvoiceItem::count());
    }

    public function test_user_supplied_content_is_escaped(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => '<b>Bold Customer</b>']);
        $invoice = $this->draftFor($user, $customer, [
            ['name' => '<i>Italic Item</i>', 'description' => "<script>alert('d')</script>\nLine two", 'quantity' => '1', 'unit_price' => '1'],
        ], ['notes' => "<script>alert('n')</script>", 'tax_label' => '<u>Tax</u>', 'tax_rate' => '1']);

        $show = $this->actingAs($user)->get(route('invoices.show', $invoice));
        $show->assertDontSee('<b>Bold Customer</b>', false)
            ->assertDontSee('<i>Italic Item</i>', false)
            ->assertDontSee("<script>alert('d')</script>", false)
            ->assertDontSee("<script>alert('n')</script>", false)
            ->assertDontSee('<u>Tax</u>', false)
            ->assertSee('&lt;script&gt;', false)
            ->assertSee('<br />', false);

        $this->actingAs($user)->get(route('invoices.index'))->assertDontSee('<b>Bold Customer</b>', false);
        $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertDontSee('<i>Italic Item</i>', false);
    }

    public function test_forms_include_csrf_tokens(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor($user);
        $issued = $this->issuedFor($user);

        $this->actingAs($user)->get(route('invoices.edit', $draft))->assertSee('name="_token"', false);
        $this->actingAs($user)->get(route('invoices.show', $issued))->assertSee('name="_token"', false);
        $this->actingAs($user)->get(route('invoices.cancel.confirm', $issued))->assertSee('name="_token"', false);
    }
}
