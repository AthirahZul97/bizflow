<?php

namespace Tests\Feature\Invoices;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoices keep their customers and products from being deleted.
 */
class InvoiceDeletionGuardsTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    public function test_a_customer_with_invoices_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Billed Customer']);
        $this->draftFor($user, $customer);

        $this->actingAs($user)->get(route('customers.delete', $customer))
            ->assertOk()
            ->assertSee('has invoices and cannot be deleted')
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->actingAs($user)->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('error', 'This customer has invoices and cannot be deleted.');

        $this->assertModelExists($customer);

        $this->actingAs($user)->followingRedirects()->delete(route('customers.destroy', $customer))
            ->assertSee('alert-danger', false)
            ->assertSee('This customer has invoices and cannot be deleted.');
    }

    public function test_a_customer_without_invoices_can_still_be_deleted(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);

        $this->actingAs($user)->get(route('customers.delete', $customer))
            ->assertSee('name="_method" value="DELETE"', false);

        $this->actingAs($user)->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertModelMissing($customer);
    }

    public function test_a_product_used_on_invoices_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create(['name' => 'Used Item']);
        $this->draftFor($user, items: [['product_id' => $product->id, 'quantity' => '1']]);

        $this->actingAs($user)->get(route('products.delete', $product))
            ->assertOk()
            ->assertSee('is used on invoices and cannot be deleted')
            ->assertSee('Mark it inactive')
            ->assertDontSee('name="_method" value="DELETE"', false);

        $this->actingAs($user)->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('error', 'This item is used on invoices and cannot be deleted. Mark it inactive instead.');

        $this->assertModelExists($product);
    }

    public function test_a_product_not_used_on_invoices_can_still_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($product->user)->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'));

        $this->assertModelMissing($product);
    }

    public function test_database_restricts_deleting_a_referenced_customer(): void
    {
        $user = User::factory()->create();
        $invoice = $this->draftFor($user);

        $this->expectException(QueryException::class);

        Customer::whereKey($invoice->customer_id)->delete();
    }

    public function test_database_restricts_deleting_a_referenced_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->for($user)->create();
        $this->draftFor($user, items: [['product_id' => $product->id, 'quantity' => '1']]);

        $this->expectException(QueryException::class);

        Product::whereKey($product->id)->delete();
    }

    public function test_deleting_an_invoice_deletes_its_lines(): void
    {
        $user = User::factory()->create();
        $invoice = $this->draftFor($user, items: [
            ['name' => 'A', 'quantity' => '1', 'unit_price' => '1'],
            ['name' => 'B', 'quantity' => '1', 'unit_price' => '1'],
        ]);

        Invoice::whereKey($invoice->id)->delete();

        $this->assertSame(0, InvoiceItem::count());
    }
}
