<?php

namespace Tests\Feature\RecurringInvoices;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\RecurringInvoiceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Business A's owner must never read, change, reference or generate from
 * Business B's recurring invoices, through pages, forms, the service or the database.
 */
class RecurringInvoiceIsolationTest extends TestCase
{
    use CreatesRecurringInvoices;
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerA = User::factory()->create();
        $this->ownerB = User::factory()->create();
    }

    public function test_the_list_shows_only_the_current_businesss_recurring_invoices(): void
    {
        $this->recurringFor($this->ownerA, overrides: ['name' => 'Schedule Of A']);
        $this->recurringFor($this->ownerB, overrides: ['name' => 'Schedule Of B']);

        $this->actingAs($this->ownerA)->get(route('recurring-invoices.index'))
            ->assertSee('Schedule Of A')
            ->assertDontSee('Schedule Of B');
    }

    public function test_another_businesss_recurring_invoice_is_not_found_for_every_action(): void
    {
        $theirs = $this->recurringFor($this->ownerB);
        $this->actingAs($this->ownerA);

        foreach (['show', 'edit', 'delete', 'cancel.confirm'] as $route) {
            $this->get(route("recurring-invoices.{$route}", $theirs))->assertNotFound();
        }
        $this->put(route('recurring-invoices.update', $theirs), $this->recurringPayload($this->customerFor($this->ownerA)))->assertNotFound();
        $this->delete(route('recurring-invoices.destroy', $theirs))->assertNotFound();
        foreach (['pause', 'resume', 'cancel', 'generate'] as $action) {
            $this->post(route("recurring-invoices.{$action}", $theirs))->assertNotFound();
        }

        $theirs->refresh();
        $this->assertTrue($theirs->isActive());
        $this->assertSame('Monthly website maintenance', $theirs->name);
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_form_cannot_use_another_businesss_customer_or_product(): void
    {
        $ownCustomer = $this->customerFor($this->ownerA);
        $foreignCustomer = $this->customerFor($this->ownerB);
        $foreignProduct = Product::factory()->ownedBy($this->ownerB)->create();

        $this->actingAs($this->ownerA)
            ->post(route('recurring-invoices.store'), $this->recurringPayload($foreignCustomer))
            ->assertSessionHasErrors(['customer_id' => 'The selected customer id is invalid.']);
        $this->actingAs($this->ownerA)
            ->post(route('recurring-invoices.store'), $this->recurringPayload($ownCustomer, [['product_id' => $foreignProduct->id, 'quantity' => '1']]))
            ->assertSessionHasErrors(['items.0.product_id' => 'The selected product is invalid.']);

        // Editing an own schedule cannot switch it to a foreign customer either.
        $own = $this->recurringFor($this->ownerA, $ownCustomer);
        $this->actingAs($this->ownerA)
            ->put(route('recurring-invoices.update', $own), $this->recurringPayload($foreignCustomer))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(1, RecurringInvoice::count());
        $this->assertSame($ownCustomer->id, $own->fresh()->customer_id);
    }

    public function test_the_create_form_lists_only_the_current_businesss_customers_and_products(): void
    {
        $this->customerFor($this->ownerA, ['name' => 'Customer Of A']);
        $this->customerFor($this->ownerB, ['name' => 'Customer Of B']);
        Product::factory()->ownedBy($this->ownerA)->create(['name' => 'Product Of A']);
        Product::factory()->ownedBy($this->ownerB)->create(['name' => 'Product Of B']);

        $this->actingAs($this->ownerA)->get(route('recurring-invoices.create'))
            ->assertSee('Customer Of A')->assertSee('Product Of A')
            ->assertDontSee('Customer Of B')->assertDontSee('Product Of B');
    }

    public function test_the_service_refuses_foreign_customers_products_and_schedules(): void
    {
        $service = app(RecurringInvoiceService::class);
        $businessA = $this->businessOf($this->ownerA);
        $ownCustomer = $this->customerFor($this->ownerA);
        $foreignCustomer = $this->customerFor($this->ownerB);
        $foreignProduct = Product::factory()->ownedBy($this->ownerB)->create();
        $theirs = $this->recurringFor($this->ownerB);

        $this->assertThrows(fn () => $service->save($businessA, $this->ownerA, $this->recurringPayload($foreignCustomer)), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->save($businessA, $this->ownerA, $this->recurringPayload($ownCustomer, [
            ['product_id' => $foreignProduct->id, 'name' => 'X', 'quantity' => '1', 'unit_price' => '1.00'],
        ])), InvalidArgumentException::class);

        // Every operation on another business's schedule is "not found", even called directly.
        $this->assertThrows(fn () => $service->save($businessA, $this->ownerA, $this->recurringPayload($ownCustomer), $theirs), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->pause($businessA, $theirs), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->cancel($businessA, $theirs), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->delete($businessA, $theirs), ModelNotFoundException::class);
        $this->assertNull($service->generateNext($businessA, $theirs));
        $this->assertSame(['invoices' => [], 'error' => null], $service->generateDue($businessA, $theirs));

        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, RecurringInvoice::count());
        $this->assertTrue($theirs->fresh()->isActive());
    }

    public function test_the_database_rejects_cross_business_links(): void
    {
        $mine = $this->recurringFor($this->ownerA);
        $foreignCustomer = $this->customerFor($this->ownerB);
        $foreignInvoice = Invoice::factory()->ownedBy($this->ownerB)->create();

        // A schedule cannot bill another business's customer...
        $this->assertThrows(fn () => $mine->forceFill(['customer_id' => $foreignCustomer->id])->save(), QueryException::class);
        // ...and an invoice cannot link to another business's schedule.
        $this->assertThrows(fn () => $foreignInvoice->forceFill([
            'recurring_invoice_id' => $mine->id, 'recurring_occurrence_on' => '2026-01-31',
        ])->save(), QueryException::class);

        $this->assertNull($foreignInvoice->fresh()->recurring_invoice_id);
    }

    public function test_generated_invoices_stay_in_their_own_business(): void
    {
        $mine = $this->recurringFor($this->ownerA);
        $this->recurringFor($this->ownerB);
        $this->runGeneration();

        $invoice = $this->businessOf($this->ownerA)->invoices()->sole();
        $theirInvoice = $this->businessOf($this->ownerB)->invoices()->sole();
        $this->assertSame($mine->id, $invoice->recurring_invoice_id);

        $this->actingAs($this->ownerA)->get(route('invoices.show', $theirInvoice))->assertNotFound();
        $this->actingAs($this->ownerA)->get(route('recurring-invoices.show', $mine))
            ->assertSee(route('invoices.show', $invoice))
            ->assertDontSee(route('invoices.show', $theirInvoice));
        $this->actingAs($this->ownerA)->get(route('invoices.index'))->assertOk();
    }

    public function test_a_customer_with_a_recurring_invoice_cannot_be_deleted(): void
    {
        $customer = $this->customerFor($this->ownerA);
        $this->recurringFor($this->ownerA, $customer, overrides: ['start_date' => '2026-06-01']);

        $this->actingAs($this->ownerA)->get(route('customers.delete', $customer))
            ->assertOk()->assertSee('has a recurring invoice and cannot be deleted');
        $this->actingAs($this->ownerA)->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('error', 'This customer has a recurring invoice and cannot be deleted.');
        $this->assertModelExists($customer);

        // The database refuses too, whatever the application does.
        $this->assertThrows(fn () => Customer::whereKey($customer->id)->delete(), QueryException::class);
    }

    public function test_a_product_used_by_a_recurring_invoice_cannot_be_deleted(): void
    {
        $product = Product::factory()->ownedBy($this->ownerA)->create();
        $this->recurringFor($this->ownerA, items: [['product_id' => $product->id, 'quantity' => '1']], overrides: ['start_date' => '2026-06-01']);

        $this->actingAs($this->ownerA)->get(route('products.delete', $product))
            ->assertOk()->assertSee('is used on a recurring invoice and cannot be deleted');
        $this->actingAs($this->ownerA)->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('error', 'This item is used on a recurring invoice and cannot be deleted. Remove it from the recurring invoice first.');
        $this->assertModelExists($product);
        $this->assertThrows(fn () => Product::whereKey($product->id)->delete(), QueryException::class);
    }

    public function test_customers_and_products_without_recurring_invoices_delete_as_before(): void
    {
        $customer = $this->customerFor($this->ownerA);
        $product = Product::factory()->ownedBy($this->ownerA)->create();

        $this->actingAs($this->ownerA)->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'));
        $this->actingAs($this->ownerA)->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));

        $this->assertModelMissing($customer);
        $this->assertModelMissing($product);
    }

    public function test_a_user_without_a_business_cannot_reach_recurring_invoices(): void
    {
        $user = User::factory()->withoutBusiness()->create();

        $this->actingAs($user)->get(route('recurring-invoices.index'))->assertForbidden();
        $this->actingAs($user)->get(route('recurring-invoices.create'))->assertForbidden();
    }
}
