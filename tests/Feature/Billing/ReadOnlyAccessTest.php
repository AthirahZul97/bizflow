<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Http\Middleware\EnsureSubscriptionWritable;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReadOnlyAccessTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    private Customer $customer;

    private Product $product;

    private Invoice $draft;

    private Invoice $issued;

    private Invoice $paid;

    private Expense $expense;

    private RecurringInvoice $recurring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
        $this->owner = User::factory()->create();
        $this->customer = Customer::factory()->ownedBy($this->owner)->create(['name' => 'Original Customer']);
        $this->product = Product::factory()->ownedBy($this->owner)->create(['name' => 'Original Product']);
        $this->draft = Invoice::factory()->ownedBy($this->owner)->create(['customer_id' => $this->customer->id]);
        $this->issued = Invoice::factory()->ownedBy($this->owner)->issued()->create(['customer_id' => $this->customer->id, 'customer_email' => 'c@example.test']);
        $this->paid = Invoice::factory()->ownedBy($this->owner)->paid()->create(['customer_id' => $this->customer->id]);
        $this->expense = Expense::factory()->ownedBy($this->owner)->create();
        $this->recurring = RecurringInvoice::factory()->ownedBy($this->owner)->create(['customer_id' => $this->customer->id]);
    }

    private function readOnly(): void
    {
        $this->makeReadOnly($this->owner);
    }

    // ---- reading keeps working ------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function readablePages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'customers' => ['customers.index'],
            'customer' => ['customers.show'],
            'products' => ['products.index'],
            'product' => ['products.show'],
            'invoices' => ['invoices.index'],
            'invoice' => ['invoices.show'],
            'recurring list' => ['recurring-invoices.index'],
            'recurring' => ['recurring-invoices.show'],
            'expenses' => ['expenses.index'],
            'expense' => ['expenses.show'],
            'report summary' => ['reports.summary'],
            'report customers' => ['reports.customers'],
            'report invoices' => ['reports.invoices'],
            'report expenses' => ['reports.expenses'],
            'billing' => ['billing.show'],
            'plans' => ['billing.plans'],
        ];
    }

    private function urlFor(string $route): string
    {
        return match ($route) {
            'customers.show' => route($route, $this->customer),
            'products.show' => route($route, $this->product),
            'invoices.show' => route($route, $this->issued),
            'recurring-invoices.show' => route($route, $this->recurring),
            'expenses.show' => route($route, $this->expense),
            default => route($route),
        };
    }

    #[DataProvider('readablePages')]
    public function test_every_reading_page_still_works_in_read_only(string $route): void
    {
        $this->readOnly();

        $this->actingAs($this->owner)->get($this->urlFor($route))->assertOk();
    }

    public function test_an_existing_invoice_pdf_can_still_be_downloaded(): void
    {
        $this->readOnly();

        $response = $this->actingAs($this->owner)->get(route('invoices.pdf', $this->issued));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->owner)->get(route('invoices.pdf', $this->paid))->assertOk();
    }

    public function test_the_dashboard_shows_the_read_only_banner(): void
    {
        $this->readOnly();

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertSee('id="subscription-notice"', false)
            ->assertSee('read-only')
            ->assertSee('Your data is safe');
    }

    public function test_logging_out_still_works(): void
    {
        $this->readOnly();

        $this->actingAs($this->owner)->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    // ---- writing is refused ---------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function writes(): array
    {
        return [
            'create customer' => ['post', 'customers.store', null],
            'update customer' => ['put', 'customers.update', 'customer'],
            'delete customer' => ['delete', 'customers.destroy', 'customer'],
            'create product' => ['post', 'products.store', null],
            'update product' => ['put', 'products.update', 'product'],
            'delete product' => ['delete', 'products.destroy', 'product'],
            'create invoice' => ['post', 'invoices.store', null],
            'update invoice' => ['put', 'invoices.update', 'draft'],
            'delete draft' => ['delete', 'invoices.destroy', 'draft'],
            'issue invoice' => ['post', 'invoices.issue', 'draft'],
            'mark paid' => ['post', 'invoices.mark-paid', 'issued'],
            'mark unpaid' => ['post', 'invoices.mark-unpaid', 'paid'],
            'cancel invoice' => ['post', 'invoices.cancel', 'issued'],
            'email invoice' => ['post', 'invoices.email.store', 'issued'],
            'create recurring' => ['post', 'recurring-invoices.store', null],
            'update recurring' => ['put', 'recurring-invoices.update', 'recurring'],
            'delete recurring' => ['delete', 'recurring-invoices.destroy', 'recurring'],
            'pause recurring' => ['post', 'recurring-invoices.pause', 'recurring'],
            'resume recurring' => ['post', 'recurring-invoices.resume', 'recurring'],
            'cancel recurring' => ['post', 'recurring-invoices.cancel', 'recurring'],
            'generate recurring' => ['post', 'recurring-invoices.generate', 'recurring'],
            'create expense' => ['post', 'expenses.store', null],
            'update expense' => ['put', 'expenses.update', 'expense'],
            'delete expense' => ['delete', 'expenses.destroy', 'expense'],
            'update business profile' => ['put', 'business.profile.update', null],
        ];
    }

    #[DataProvider('writes')]
    public function test_every_write_is_refused_in_read_only_with_the_reason(string $method, string $route, ?string $record): void
    {
        $this->readOnly();
        $url = $record === null ? route($route) : route($route, $this->{$record});

        $this->actingAs($this->owner)->{$method}($url, ['name' => 'Hacked'])
            ->assertForbidden()
            ->assertSee('read-only');
    }

    public function test_nothing_changed_after_all_those_refused_writes(): void
    {
        $this->readOnly();

        foreach (self::writes() as [$method, $route, $record]) {
            $url = $record === null ? route($route) : route($route, $this->{$record});
            $this->actingAs($this->owner)->{$method}($url, ['name' => 'Hacked', 'recipient' => 'invoice']);
        }

        $this->assertSame('Original Customer', $this->customer->fresh()->name);
        $this->assertSame('Original Product', $this->product->fresh()->name);
        $this->assertSame(InvoiceStatus::Draft, $this->draft->fresh()->status);
        $this->assertSame(InvoiceStatus::Issued, $this->issued->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $this->paid->fresh()->status);
        $this->assertSame(1, $this->businessOf($this->owner)->customers()->count());
        $this->assertSame(3, $this->businessOf($this->owner)->invoices()->count());
        $this->assertSame(1, $this->businessOf($this->owner)->recurringInvoices()->count());
        $this->assertSame(0, InvoiceEmail::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forms(): array
    {
        return [
            'new customer' => ['customers.create'],
            'new product' => ['products.create'],
            'new invoice' => ['invoices.create'],
            'new recurring invoice' => ['recurring-invoices.create'],
            'new expense' => ['expenses.create'],
            'business profile' => ['business.profile.edit'],
        ];
    }

    #[DataProvider('forms')]
    public function test_forms_that_could_not_be_submitted_are_refused_up_front(string $route): void
    {
        $this->readOnly();

        $this->actingAs($this->owner)->get(route($route))->assertForbidden()->assertSee('read-only');
    }

    public function test_edit_forms_for_existing_records_are_refused_too(): void
    {
        $this->readOnly();
        $this->actingAs($this->owner);

        $this->get(route('customers.edit', $this->customer))->assertForbidden();
        $this->get(route('products.edit', $this->product))->assertForbidden();
        $this->get(route('invoices.edit', $this->draft))->assertForbidden();
        $this->get(route('expenses.edit', $this->expense))->assertForbidden();
        $this->get(route('recurring-invoices.edit', $this->recurring))->assertForbidden();
    }

    public function test_read_only_policy_checks_come_after_ownership_so_other_businesses_stay_404(): void
    {
        $other = User::factory()->create();
        $theirs = Customer::factory()->ownedBy($other)->create();
        $this->readOnly();

        // A GET goes through the policy: ownership first, so 404, never a 403 about read-only.
        $this->actingAs($this->owner)->get(route('customers.edit', $theirs))->assertNotFound();
        $this->actingAs($this->owner)->get(route('customers.show', $theirs))->assertNotFound();
    }

    public function test_a_forged_record_id_and_a_missing_one_are_refused_identically_in_read_only(): void
    {
        $other = User::factory()->create();
        $theirs = Customer::factory()->ownedBy($other)->create();
        $this->readOnly();

        $forged = $this->actingAs($this->owner)->delete(route('customers.destroy', $theirs));
        $missing = $this->actingAs($this->owner)->delete(route('customers.destroy', 999999));

        $forged->assertForbidden();
        $missing->assertForbidden();
        $this->assertModelExists($theirs);
    }

    public function test_the_read_only_check_runs_before_route_model_binding(): void
    {
        $this->readOnly();

        // If binding ran first, a missing record would be a 404 and a forged one a 403: a
        // difference that reveals which IDs exist in other businesses.
        foreach (['invoices.destroy', 'products.destroy', 'expenses.destroy', 'recurring-invoices.destroy'] as $route) {
            $this->actingAs($this->owner)->delete(route($route, 987654))->assertForbidden();
        }
    }

    // ---- fail closed ----------------------------------------------------------------------

    public function test_an_unlisted_new_write_route_is_refused_by_default_in_read_only(): void
    {
        Route::middleware(['web', 'auth', 'business', 'subscription.writable'])
            ->post('/__probe', fn () => response('wrote', 200))->name('probe.write');
        Route::middleware(['web', 'auth', 'business', 'subscription.writable'])
            ->get('/__probe-read', fn () => response('read', 200))->name('probe.read');

        $this->actingAs($this->owner)->post('/__probe')->assertOk();

        $this->readOnly();
        $this->actingAs($this->owner)->post('/__probe')->assertForbidden();
        $this->actingAs($this->owner)->get('/__probe-read')->assertOk();
    }

    public function test_every_business_write_route_is_covered_by_the_middleware(): void
    {
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $unsafe = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
            $uses = in_array('business', $route->gatherMiddleware(), true);

            if ($unsafe && $uses && ! in_array('subscription.writable', $route->gatherMiddleware(), true)) {
                $unprotected[] = $route->uri();
            }
        }

        $this->assertSame([], $unprotected);
    }

    public function test_only_the_three_billing_actions_are_allow_listed(): void
    {
        $this->assertSame(
            ['billing.change', 'billing.cancel', 'billing.resume'],
            EnsureSubscriptionWritable::READ_ONLY_ALLOWED_ROUTES,
        );

        foreach (EnsureSubscriptionWritable::READ_ONLY_ALLOWED_ROUTES as $name) {
            $this->assertTrue(Route::has($name));
            $this->assertStringStartsWith('billing.', $name);
        }
    }

    // ---- billing keeps working ------------------------------------------------------------

    public function test_a_read_only_owner_can_switch_to_free_and_regain_access(): void
    {
        $this->readOnly();
        $this->actingAs($this->owner)->put(route('customers.update', $this->customer), ['name' => 'X'])->assertForbidden();

        $this->actingAs($this->owner)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()])
            ->assertRedirect(route('billing.show'));

        $this->actingAs($this->owner)->put(route('customers.update', $this->customer), ['name' => 'Back In Business'])->assertRedirect();
        $this->assertSame('Back In Business', $this->customer->fresh()->name);
    }

    public function test_existing_data_is_untouched_by_expiry_and_by_regaining_access(): void
    {
        $before = [
            $this->customer->fresh()->toArray(), $this->issued->fresh()->toArray(),
            $this->recurring->fresh()->toArray(), $this->expense->fresh()->toArray(),
        ];

        $this->readOnly();
        $this->actingAs($this->owner)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()]);

        $this->assertEquals($before, [
            $this->customer->fresh()->toArray(), $this->issued->fresh()->toArray(),
            $this->recurring->fresh()->toArray(), $this->expense->fresh()->toArray(),
        ]);
    }

    // ---- grace and full -------------------------------------------------------------------

    public function test_grace_blocks_nothing_and_shows_a_warning(): void
    {
        $this->lapsedPaid($this->owner, 2);

        $this->actingAs($this->owner)->put(route('customers.update', $this->customer), ['name' => 'Edited In Grace'])->assertRedirect();
        $this->assertSame('Edited In Grace', $this->customer->fresh()->name);
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertSee('alert-warning', false)
            ->assertSee('full access until');
    }

    public function test_full_access_shows_no_banner_for_legacy_businesses(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertDontSee('subscription-notice');
    }

    public function test_a_stale_stored_status_does_not_keep_a_lapsed_business_writing(): void
    {
        $subscription = $this->paidWithDaysLeft($this->owner, 5);
        $subscription->forceFill(['status' => 'active'])->save();
        $this->travelTo('2027-03-01 12:00:00');

        $this->actingAs($this->owner)->put(route('customers.update', $this->customer), ['name' => 'Late'])->assertForbidden();
    }

    public function test_the_page_after_choosing_free_is_writable_but_limited(): void
    {
        $this->readOnly();
        $this->actingAs($this->owner)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()]);

        $this->actingAs($this->owner)->get(route('recurring-invoices.create'))
            ->assertForbidden()
            ->assertSee("doesn't include recurring invoices");
    }
}
