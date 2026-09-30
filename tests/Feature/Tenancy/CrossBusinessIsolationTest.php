<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Invoices\CreatesInvoices;
use Tests\TestCase;

/**
 * Business A's owner must never read, change or reference Business B's data,
 * whether through pages, forms, direct service calls or the database.
 */
class CrossBusinessIsolationTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Business $businessA;

    private Business $businessB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerA = User::factory()->create(['name' => 'Alice Owner']);
        $this->ownerB = User::factory()->create(['name' => 'Bob Owner']);
        $this->businessA = $this->businessOf($this->ownerA);
        $this->businessB = $this->businessOf($this->ownerB);
    }

    public function test_each_module_lists_only_the_current_businesss_records(): void
    {
        Customer::factory()->ownedBy($this->ownerA)->create(['name' => 'Customer Of A']);
        Customer::factory()->ownedBy($this->ownerB)->create(['name' => 'Customer Of B']);
        Product::factory()->ownedBy($this->ownerA)->create(['name' => 'Product Of A']);
        Product::factory()->ownedBy($this->ownerB)->create(['name' => 'Product Of B']);
        Expense::factory()->ownedBy($this->ownerA)->create(['description' => 'Expense Of A', 'expense_date' => '2026-09-10']);
        Expense::factory()->ownedBy($this->ownerB)->create(['description' => 'Expense Of B', 'expense_date' => '2026-09-10']);
        $this->issuedFor($this->ownerA, $this->customerFor($this->ownerA, ['name' => 'Invoiced By A']));
        $this->issuedFor($this->ownerB, $this->customerFor($this->ownerB, ['name' => 'Invoiced By B']));

        foreach ([
            'customers.index' => ['Customer Of A', 'Customer Of B'],
            'products.index' => ['Product Of A', 'Product Of B'],
            'expenses.index' => ['Expense Of A', 'Expense Of B'],
            'invoices.index' => ['Invoiced By A', 'Invoiced By B'],
        ] as $route => [$mine, $theirs]) {
            $this->actingAs($this->ownerA)->get(route($route))
                ->assertOk()
                ->assertSee($mine)
                ->assertDontSee($theirs);
        }
    }

    public function test_another_businesss_records_are_not_found_for_every_action(): void
    {
        $customer = Customer::factory()->ownedBy($this->ownerB)->create();
        $product = Product::factory()->ownedBy($this->ownerB)->create();
        $expense = Expense::factory()->ownedBy($this->ownerB)->create();
        $draft = $this->draftFor($this->ownerB);
        $issued = $this->issuedFor($this->ownerB);

        $this->actingAs($this->ownerA);

        foreach (['customers' => $customer, 'products' => $product, 'expenses' => $expense] as $prefix => $record) {
            foreach (['show', 'edit', 'delete'] as $action) {
                $this->get(route("{$prefix}.{$action}", $record))->assertNotFound();
            }
            $this->put(route("{$prefix}.update", $record), [])->assertNotFound();
            $this->delete(route("{$prefix}.destroy", $record))->assertNotFound();
            $this->assertModelExists($record);
        }

        foreach (['show', 'edit', 'delete'] as $action) {
            $this->get(route("invoices.{$action}", $draft))->assertNotFound();
        }
        $this->put(route('invoices.update', $draft), [])->assertNotFound();
        $this->delete(route('invoices.destroy', $draft))->assertNotFound();
        $this->post(route('invoices.issue', $draft))->assertNotFound();
        $this->post(route('invoices.mark-paid', $issued), ['paid_at' => '2026-09-20'])->assertNotFound();
        $this->post(route('invoices.cancel', $issued))->assertNotFound();
        $this->get(route('invoices.pdf', $issued))->assertNotFound();

        $this->assertModelExists($draft);
        $this->assertSame('issued', $issued->fresh()->status->value);
    }

    public function test_an_invoice_form_cannot_use_another_businesss_customer_or_product(): void
    {
        $ownCustomer = $this->customerFor($this->ownerA);
        $foreignCustomer = $this->customerFor($this->ownerB);
        $foreignProduct = Product::factory()->ownedBy($this->ownerB)->create();

        $this->actingAs($this->ownerA)
            ->post(route('invoices.store'), $this->invoicePayload($foreignCustomer))
            ->assertSessionHasErrors(['customer_id' => 'The selected customer id is invalid.']);

        $this->actingAs($this->ownerA)
            ->post(route('invoices.store'), $this->invoicePayload($ownCustomer, [[
                'product_id' => $foreignProduct->id, 'name' => null, 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => null,
            ]]))
            ->assertSessionHasErrors(['items.0.product_id' => 'The selected product is invalid.']);

        // Editing an own draft cannot switch it to a foreign customer either.
        $draft = $this->draftFor($this->ownerA, $ownCustomer);
        $this->actingAs($this->ownerA)
            ->put(route('invoices.update', $draft), $this->invoicePayload($foreignCustomer))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(1, Invoice::count());
        $this->assertSame($ownCustomer->id, $draft->fresh()->customer_id);
    }

    public function test_the_invoice_service_refuses_foreign_customers_products_and_invoices(): void
    {
        $service = app(InvoiceService::class);
        $ownCustomer = $this->customerFor($this->ownerA);
        $foreignCustomer = $this->customerFor($this->ownerB);
        $foreignProduct = Product::factory()->ownedBy($this->ownerB)->create();

        $this->assertThrows(
            fn () => $service->saveDraft($this->businessA, $this->ownerA, $this->invoicePayload($foreignCustomer)),
            ModelNotFoundException::class,
        );

        $this->assertThrows(
            fn () => $service->saveDraft($this->businessA, $this->ownerA, $this->invoicePayload($ownCustomer, [[
                'product_id' => $foreignProduct->id, 'name' => 'X', 'quantity' => '1', 'unit_price' => '1.00',
            ]])),
            InvalidArgumentException::class,
            'Invoice lines may only use the business\'s own products.',
        );

        $foreignDraft = $this->draftFor($this->ownerB);
        $this->assertThrows(
            fn () => $service->saveDraft($this->businessA, $this->ownerA, $this->invoicePayload($ownCustomer), $foreignDraft),
            InvalidArgumentException::class,
            'The invoice does not belong to this business.',
        );

        $this->assertSame(1, Invoice::count());
    }

    public function test_the_database_rejects_an_invoice_for_another_businesss_customer(): void
    {
        $invoice = $this->draftFor($this->ownerA);
        $foreignCustomer = $this->customerFor($this->ownerB);

        $this->assertThrows(fn () => $invoice->forceFill(['customer_id' => $foreignCustomer->id])->save(), QueryException::class);
        $this->assertNotSame($foreignCustomer->id, $invoice->fresh()->customer_id);
    }

    public function test_forged_business_ids_are_ignored_on_every_create_form(): void
    {
        $forged = ['business_id' => $this->businessB->id];

        $this->actingAs($this->ownerA)->post(route('customers.store'), ['name' => 'Forged Customer'] + $forged);
        $this->actingAs($this->ownerA)->post(route('products.store'), [
            'type' => 'service', 'name' => 'Forged Product', 'selling_price' => '1', 'is_active' => '1',
        ] + $forged);
        $this->actingAs($this->ownerA)->post(route('expenses.store'), [
            'expense_date' => '2026-09-10', 'category' => 'office', 'description' => 'Forged Expense', 'amount' => '1',
        ] + $forged);
        $this->actingAs($this->ownerA)->post(route('invoices.store'), $this->invoicePayload($this->customerFor($this->ownerA), overrides: $forged));

        $this->assertSame($this->businessA->id, Customer::where('name', 'Forged Customer')->sole()->business_id);
        $this->assertSame($this->businessA->id, Product::where('name', 'Forged Product')->sole()->business_id);
        $this->assertSame($this->businessA->id, Expense::where('description', 'Forged Expense')->sole()->business_id);
        $this->assertSame($this->businessA->id, Invoice::sole()->business_id);
        $this->assertSame(0, $this->businessB->customers()->count() + $this->businessB->products()->count()
            + $this->businessB->expenses()->count() + $this->businessB->invoices()->count());
    }

    public function test_the_dashboard_and_reports_show_only_the_current_businesss_figures(): void
    {
        $theirs = $this->customerFor($this->ownerB, ['name' => 'Their Big Customer']);
        $invoice = $this->issuedFor($this->ownerB, $theirs, ['items' => [[
            'product_id' => null, 'name' => 'Big job', 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => '777777.00',
        ]]]);
        app(InvoiceService::class)->markPaid($invoice, '2026-09-20');
        Expense::factory()->ownedBy($this->ownerB)->create(['description' => 'Their Big Expense', 'amount' => '555555.00', 'expense_date' => '2026-09-10']);

        $pages = [
            route('dashboard'),
            route('reports.summary', ['period' => 'this_year']),
            route('reports.customers', ['period' => 'this_year']),
            route('reports.invoices', ['period' => 'this_year']),
            route('reports.invoices', ['period' => 'this_year', 'view' => 'received']),
            route('reports.expenses', ['period' => 'this_year']),
        ];

        foreach ($pages as $url) {
            $this->actingAs($this->ownerA)->get($url.(str_contains($url, '?') ? '&' : '?').'business_id='.$this->businessB->id)
                ->assertOk()
                ->assertDontSee('777,777.00')
                ->assertDontSee('555,555.00')
                ->assertDontSee('Their Big Customer')
                ->assertDontSee('Their Big Expense');

            // The same pages do show those figures to Business B's owner.
            $this->actingAs($this->ownerB)->get($url)->assertOk();
        }

        $this->actingAs($this->ownerB)->get(route('reports.summary', ['period' => 'this_year']))->assertSee('777,777.00');
    }
}
