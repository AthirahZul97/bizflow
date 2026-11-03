<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementGuard;
use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Exceptions\EntitlementException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class CustomerProductLimitTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
        $this->owner = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(string $name = 'New Customer'): array
    {
        return ['name' => $name, 'email' => 'new@example.com'];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(string $name = 'New Item'): array
    {
        return ['name' => $name, 'type' => 'product', 'selling_price' => '10.00', 'is_active' => '1'];
    }

    // ---- customers ------------------------------------------------------------------------

    public function test_a_customer_can_be_created_below_the_limit(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 2]);
        Customer::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload())
            ->assertRedirect();

        $this->assertSame(2, $this->businessOf($this->owner)->customers()->count());
    }

    public function test_a_customer_cannot_be_created_at_the_limit(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 2]);
        Customer::factory()->count(2)->ownedBy($this->owner)->create();

        // The policy refuses first (403 with the reason); the guard behind it is the atomic net.
        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload('Over The Limit'))
            ->assertForbidden()
            ->assertSee('limit of 2 customers');
        $this->assertSame(2, $this->businessOf($this->owner)->customers()->count());
        $this->assertDatabaseMissing('customers', ['name' => 'Over The Limit']);
    }

    public function test_the_create_form_is_refused_with_an_upgrade_message_at_the_limit(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 1]);
        Customer::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->get(route('customers.create'))
            ->assertForbidden()
            ->assertSee('limit of 1 customers');
    }

    public function test_a_plan_that_does_not_mention_customers_blocks_creation(): void
    {
        $plan = $this->planWith(['products.max' => 5]);
        $this->subscribe($this->owner, fn ($f) => $f->state(['plan_id' => $plan->getKey()]));

        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload())->assertForbidden();

        $this->assertSame(0, $this->businessOf($this->owner)->customers()->count());
    }

    public function test_deleting_a_customer_reopens_capacity(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 1]);
        $customer = Customer::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->delete(route('customers.destroy', $customer))->assertRedirect();
        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload())->assertRedirect();

        $this->assertSame(1, $this->businessOf($this->owner)->customers()->count());
        $this->assertSame('New Customer', $this->businessOf($this->owner)->customers()->first()->name);
    }

    public function test_a_business_above_its_limit_after_a_downgrade_keeps_everything_and_may_edit_and_delete(): void
    {
        Customer::factory()->count(4)->ownedBy($this->owner)->create(['name' => 'Kept']);
        $this->limitTo($this->owner, ['customers.max' => 2]);
        $customer = $this->businessOf($this->owner)->customers()->first();

        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload())->assertForbidden();
        $this->actingAs($this->owner)->put(route('customers.update', $customer), $this->customerPayload('Renamed'))->assertRedirect();
        $this->assertSame('Renamed', $customer->fresh()->name);
        $this->assertSame(4, $this->businessOf($this->owner)->customers()->count());

        $this->actingAs($this->owner)->delete(route('customers.destroy', $customer))->assertRedirect();
        $this->assertSame(3, $this->businessOf($this->owner)->customers()->count());
    }

    public function test_customers_of_other_businesses_do_not_count(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 1]);
        Customer::factory()->count(5)->ownedBy(User::factory()->create())->create();

        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload())->assertRedirect();

        $this->assertSame(1, $this->businessOf($this->owner)->customers()->count());
    }

    public function test_grace_still_allows_creating_customers(): void
    {
        $this->lapsedPaid($this->owner, 2);

        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload())->assertRedirect();

        $this->assertSame(1, $this->businessOf($this->owner)->customers()->count());
    }

    public function test_a_forged_business_id_cannot_create_a_customer_in_another_business(): void
    {
        $victim = User::factory()->create();
        $this->limitTo($this->owner, ['customers.max' => 5]);

        $this->actingAs($this->owner)->post(route('customers.store'), $this->customerPayload('Forged') + ['business_id' => $this->businessOf($victim)->getKey()]);

        $this->assertSame(0, $this->businessOf($victim)->customers()->count());
        $this->assertSame(1, $this->businessOf($this->owner)->customers()->count());
    }

    // ---- products -------------------------------------------------------------------------

    public function test_a_product_cannot_be_created_at_the_limit(): void
    {
        $this->limitTo($this->owner, ['products.max' => 1]);
        Product::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->post(route('products.store'), $this->productPayload('Over'))
            ->assertForbidden()
            ->assertSee('limit of 1 products and services');

        $this->assertDatabaseMissing('products', ['name' => 'Over']);
    }

    public function test_inactive_products_still_use_up_the_limit(): void
    {
        $this->limitTo($this->owner, ['products.max' => 2]);
        Product::factory()->count(2)->inactive()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->post(route('products.store'), $this->productPayload('Blocked'))->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Blocked']);
    }

    public function test_deactivating_a_product_does_not_let_a_business_bypass_its_limit(): void
    {
        $this->limitTo($this->owner, ['products.max' => 1]);
        $product = Product::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->put(route('products.update', $product), array_merge($this->productPayload($product->name), ['is_active' => '0']))->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($this->owner)->post(route('products.store'), $this->productPayload('Second'))->assertForbidden();
        $this->assertSame(1, $this->businessOf($this->owner)->products()->count());
    }

    public function test_deleting_a_product_frees_capacity(): void
    {
        $this->limitTo($this->owner, ['products.max' => 1]);
        $product = Product::factory()->ownedBy($this->owner)->create();

        $this->actingAs($this->owner)->delete(route('products.destroy', $product))->assertRedirect();
        $this->actingAs($this->owner)->post(route('products.store'), $this->productPayload('Fresh'))->assertRedirect();

        $this->assertSame(['Fresh'], $this->businessOf($this->owner)->products()->pluck('name')->all());
    }

    // ---- the guard ------------------------------------------------------------------------

    public function test_the_guard_runs_the_insert_only_when_allowed(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 1]);
        $business = $this->businessOf($this->owner);
        $guard = app(EntitlementGuard::class);
        $ran = 0;

        $guard->create($business, Entitlement::Customers, function () use ($business, &$ran) {
            $ran++;

            return $business->customers()->create(['name' => 'First']);
        });

        try {
            $guard->create($business, Entitlement::Customers, function () use (&$ran) {
                $ran++;
            });
            $this->fail('Expected the second create to be refused');
        } catch (EntitlementException $e) {
            $this->assertSame(Entitlement::Customers, $e->check->entitlement);
        }

        $this->assertSame(1, $ran);
        $this->assertSame(1, $business->customers()->count());
    }

    public function test_the_guard_decides_from_the_database_after_the_lock_not_from_the_memo(): void
    {
        $business = $this->businessOf($this->owner);
        $this->limitTo($this->owner, ['customers.max' => 1]);
        $service = app(EntitlementService::class);

        // Memoized while there is still room...
        $this->assertTrue($service->for($business)->check(Entitlement::Customers)->allowed);
        // ...then the room disappears behind the memo's back (another request's insert). Usage is
        // never memoized, so the count is live even through the stale object.
        Customer::factory()->ownedBy($this->owner)->create();

        $this->expectException(EntitlementException::class);

        app(EntitlementGuard::class)->create($business, Entitlement::Customers, fn () => $business->customers()->create(['name' => 'Too many']));
    }

    public function test_the_guard_sees_a_subscription_change_made_behind_the_memo(): void
    {
        $business = $this->businessOf($this->owner);
        $service = app(EntitlementService::class);
        $this->assertTrue($service->for($business)->canWrite());

        $this->makeReadOnly($this->owner);

        $this->expectException(EntitlementException::class);

        app(EntitlementGuard::class)->create($business, Entitlement::Customers, fn () => $business->customers()->create(['name' => 'Nope']));
    }

    public function test_the_guard_rolls_back_when_the_insert_fails(): void
    {
        $business = $this->businessOf($this->owner);
        $this->limitTo($this->owner, ['customers.max' => 5]);
        $level = DB::transactionLevel();

        try {
            app(EntitlementGuard::class)->create($business, Entitlement::Customers, function () use ($business) {
                $business->customers()->create(['name' => 'Half done']);

                throw new LogicException('boom');
            });
        } catch (LogicException) {
        }

        $this->assertSame(0, $business->customers()->count());
        $this->assertSame($level, DB::transactionLevel());
    }

    public function test_the_guard_only_guards_limits(): void
    {
        $this->expectException(LogicException::class);

        app(EntitlementGuard::class)->create($this->businessOf($this->owner), Entitlement::InvoiceEmail, fn () => null);
    }

    public function test_the_guard_locks_the_business_row_before_counting(): void
    {
        $business = $this->businessOf($this->owner);
        $this->limitTo($this->owner, ['customers.max' => 5]);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        app(EntitlementGuard::class)->create($business, Entitlement::Customers, fn () => $business->customers()->create(['name' => 'Locked']));

        $order = [];
        foreach ($statements as $sql) {
            if (str_contains($sql, 'from "businesses"') || str_contains($sql, 'from `businesses`')) {
                $order[] = 'business';
            } elseif (str_contains($sql, 'count(*)') && str_contains($sql, 'customers')) {
                $order[] = 'count';
            } elseif (str_starts_with($sql, 'insert into "customers"') || str_starts_with($sql, 'insert into `customers`')) {
                $order[] = 'insert';
            }
        }
        $this->assertSame(['business', 'count', 'insert'], array_slice($order, array_search('business', $order), 3));
    }
}
