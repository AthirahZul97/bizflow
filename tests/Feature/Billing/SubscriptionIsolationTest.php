<?php

namespace Tests\Feature\Billing;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The commercial layer must not weaken tenant isolation: ownership is decided first, so
 * another business's records stay 404 whatever state the caller's own subscription is in.
 */
class SubscriptionIsolationTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    private User $victim;

    /** @var array<string, mixed> */
    private array $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
        $this->owner = User::factory()->create();
        $this->victim = User::factory()->create();
        $this->theirs = [
            'customer' => Customer::factory()->ownedBy($this->victim)->create(),
            'product' => Product::factory()->ownedBy($this->victim)->create(),
            'draft' => Invoice::factory()->ownedBy($this->victim)->create(),
            'issued' => Invoice::factory()->ownedBy($this->victim)->issued()->create(['customer_email' => 'x@example.test']),
            'expense' => Expense::factory()->ownedBy($this->victim)->create(),
            'recurring' => RecurringInvoice::factory()->ownedBy($this->victim)->create(),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'customer' => ['customers.show', 'customer'],
            'customer edit' => ['customers.edit', 'customer'],
            'product' => ['products.show', 'product'],
            'product edit' => ['products.edit', 'product'],
            'invoice' => ['invoices.show', 'issued'],
            'invoice edit' => ['invoices.edit', 'draft'],
            'invoice pdf' => ['invoices.pdf', 'issued'],
            'invoice email form' => ['invoices.email.create', 'issued'],
            'expense' => ['expenses.show', 'expense'],
            'expense edit' => ['expenses.edit', 'expense'],
            'recurring' => ['recurring-invoices.show', 'recurring'],
            'recurring edit' => ['recurring-invoices.edit', 'recurring'],
        ];
    }

    #[DataProvider('pages')]
    public function test_another_businesss_records_are_404_when_the_caller_is_read_only(string $route, string $record): void
    {
        $this->makeReadOnly($this->owner);

        $this->actingAs($this->owner)->get(route($route, $this->theirs[$record]))->assertNotFound();
    }

    #[DataProvider('pages')]
    public function test_another_businesss_records_are_404_when_the_caller_is_over_a_limit(string $route, string $record): void
    {
        $this->limitTo($this->owner, ['customers.max' => 0, 'products.max' => 0, 'invoices.monthly_max' => 0, 'recurring_invoices.max' => 0, 'invoices.email' => false]);

        $this->actingAs($this->owner)->get(route($route, $this->theirs[$record]))->assertNotFound();
    }

    #[DataProvider('pages')]
    public function test_another_businesss_records_are_404_in_grace_and_on_a_trial(string $route, string $record): void
    {
        $this->lapsedPaid($this->owner, 2);
        $this->actingAs($this->owner)->get(route($route, $this->theirs[$record]))->assertNotFound();

        $this->subscribe($this->owner, fn ($f) => $f->trial());
        $this->actingAs($this->owner)->get(route($route, $this->theirs[$record]))->assertNotFound();
    }

    public function test_writes_to_another_businesss_records_are_404_when_the_caller_has_full_access(): void
    {
        $this->actingAs($this->owner);

        $this->put(route('customers.update', $this->theirs['customer']), ['name' => 'Hacked'])->assertNotFound();
        $this->delete(route('customers.destroy', $this->theirs['customer']))->assertNotFound();
        $this->post(route('invoices.issue', $this->theirs['draft']))->assertNotFound();
        $this->post(route('recurring-invoices.pause', $this->theirs['recurring']))->assertNotFound();
        $this->post(route('invoices.email.store', $this->theirs['issued']), ['recipient' => 'invoice'])->assertNotFound();

        $this->assertNotSame('Hacked', $this->theirs['customer']->fresh()->name);
    }

    public function test_the_victims_subscription_state_never_affects_the_caller_or_leaks(): void
    {
        $this->makeReadOnly($this->victim);

        $this->actingAs($this->owner)->put(route('customers.update', Customer::factory()->ownedBy($this->owner)->create()), ['name' => 'Fine'])->assertRedirect();
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertDontSee('subscription-notice');
    }

    public function test_a_read_only_caller_gets_the_same_answer_for_an_existing_and_a_missing_foreign_id_on_writes(): void
    {
        $this->makeReadOnly($this->owner);
        $this->actingAs($this->owner);

        foreach ([['delete', 'customers.destroy', 'customer'], ['post', 'invoices.issue', 'draft'], ['post', 'recurring-invoices.pause', 'recurring']] as [$method, $route, $record]) {
            $real = $this->{$method}(route($route, $this->theirs[$record]));
            $missing = $this->{$method}(route($route, 888888));

            $this->assertSame($real->getStatusCode(), $missing->getStatusCode(), $route);
            $this->assertSame($real->exception?->getMessage(), $missing->exception?->getMessage(), $route);
        }
    }

    public function test_usage_counts_one_business_only(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 1, 'products.max' => 1]);
        Customer::factory()->count(9)->ownedBy($this->victim)->create();
        Product::factory()->count(9)->ownedBy($this->victim)->create();
        $victimCustomers = $this->businessOf($this->victim)->customers()->count();

        $this->actingAs($this->owner)->post(route('customers.store'), ['name' => 'Mine'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('products.store'), ['name' => 'Mine', 'type' => 'product', 'selling_price' => '1.00', 'is_active' => '1'])->assertRedirect();

        $this->assertSame(1, $this->businessOf($this->owner)->customers()->count());
        $this->assertSame($victimCustomers, $this->businessOf($this->victim)->customers()->count());
    }

    public function test_a_forged_business_id_on_any_billing_post_is_ignored(): void
    {
        $this->paidWithDaysLeft($this->owner, 20);
        $victimBefore = $this->businessOf($this->victim)->currentSubscription()->sole()->only(['id', 'plan_id', 'cancel_at_period_end']);

        foreach (['billing.cancel', 'billing.resume', 'billing.change'] as $route) {
            $this->actingAs($this->owner)->post(route($route), [
                'business_id' => $this->businessOf($this->victim)->getKey(),
                'subscription_id' => $this->businessOf($this->victim)->currentSubscription()->sole()->getKey(),
                'plan_id' => $this->freePlan()->getKey(),
            ]);
        }

        $this->assertEquals($victimBefore, $this->businessOf($this->victim)->currentSubscription()->sole()->only(['id', 'plan_id', 'cancel_at_period_end']));
        $this->assertSame(1, $this->businessOf($this->victim)->subscriptions()->count());
    }
}
