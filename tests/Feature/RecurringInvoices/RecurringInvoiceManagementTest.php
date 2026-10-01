<?php

namespace Tests\Feature\RecurringInvoices;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringInvoiceStatus;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\RecurringInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecurringInvoiceManagementTest extends TestCase
{
    use CreatesRecurringInvoices;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $recurring = RecurringInvoice::factory()->create();

        $this->get(route('recurring-invoices.index'))->assertRedirect(route('login'));
        $this->get(route('recurring-invoices.create'))->assertRedirect(route('login'));
        $this->post(route('recurring-invoices.store'), [])->assertRedirect(route('login'));
        $this->get(route('recurring-invoices.show', $recurring))->assertRedirect(route('login'));
        $this->post(route('recurring-invoices.generate', $recurring))->assertRedirect(route('login'));
    }

    public function test_the_list_is_empty_at_first_and_linked_from_the_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertSee(route('recurring-invoices.index'));
        $this->actingAs($user)->get(route('recurring-invoices.index'))
            ->assertOk()
            ->assertSee('You haven\'t set up any recurring invoices yet.', false);
    }

    public function test_the_create_form_defaults_to_monthly_from_today_with_the_configured_terms(): void
    {
        $user = User::factory()->create();
        $this->customerFor($user, ['name' => 'Aisyah Rahman']);

        $this->actingAs($user)->get(route('recurring-invoices.create'))
            ->assertOk()
            ->assertSee('Aisyah Rahman')
            ->assertSee('name="start_date" value="2026-01-31"', false)
            ->assertSee('value="monthly" selected', false)
            ->assertSee('name="payment_terms_days" min="0" max="365" step="1"', false)
            ->assertSee('value="30"', false)
            ->assertSee('data-invoice-lines', false);
    }

    public function test_users_can_create_a_recurring_invoice(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);

        $response = $this->actingAs($user)->post(route('recurring-invoices.store'), $this->recurringPayload($customer, overrides: [
            'end_date' => '2026-12-31',
            'discount_amount' => '50',
            'tax_label' => 'SST',
            'tax_rate' => '8',
            'notes' => 'Bank transfer to Maybank 1234.',
        ]));

        $recurring = RecurringInvoice::sole();
        $response->assertRedirect(route('recurring-invoices.show', $recurring))
            ->assertSessionHas('status', 'Recurring invoice created.');

        $this->assertSame($this->businessOf($user)->id, $recurring->business_id);
        $this->assertSame($user->id, $recurring->created_by);
        $this->assertSame($customer->id, $recurring->customer_id);
        $this->assertSame('Monthly website maintenance', $recurring->name);
        $this->assertSame(RecurringInvoiceStatus::Active, $recurring->status);
        $this->assertSame(RecurringFrequency::Monthly, $recurring->frequency);
        $this->assertSame('2026-01-31', $recurring->start_date->toDateString());
        $this->assertSame('2026-12-31', $recurring->end_date->toDateString());
        $this->assertSame('2026-01-31', $recurring->next_occurrence_on->toDateString());
        $this->assertNull($recurring->last_occurrence_on);
        $this->assertSame(14, $recurring->payment_terms_days);
        $this->assertSame('50.00', $recurring->discount_amount);
        $this->assertSame('SST', $recurring->tax_label);
        $this->assertSame('8.00', $recurring->tax_rate);

        $item = $recurring->items()->sole();
        $this->assertSame('Website maintenance', $item->name);
        $this->assertSame('1.00', $item->quantity);
        $this->assertSame('300.00', $item->unit_price);
        $this->assertSame(1, $item->position);

        // (300 - 50) + 8% = 270.00, calculated by the same calculator as invoices.
        $this->assertSame('270.00', app(RecurringInvoiceService::class)->totals($recurring)->total);
    }

    public function test_a_product_fills_blank_line_fields_and_typed_values_win(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);
        $product = Product::factory()->ownedBy($user)->create([
            'name' => 'Hosting', 'description' => 'Shared hosting', 'unit' => 'month', 'selling_price' => '35.50', 'cost_price' => '10.00',
        ]);

        $this->actingAs($user)->post(route('recurring-invoices.store'), $this->recurringPayload($customer, [
            ['product_id' => $product->id, 'name' => null, 'description' => null, 'unit' => null, 'quantity' => '12', 'unit_price' => null],
            ['product_id' => $product->id, 'name' => 'Premium hosting', 'description' => null, 'unit' => null, 'quantity' => '1', 'unit_price' => '99.00'],
        ]))->assertSessionHasNoErrors();

        [$first, $second] = RecurringInvoice::sole()->items;
        $this->assertSame(['Hosting', 'Shared hosting', 'month', '12.00', '35.50'], [$first->name, $first->description, $first->unit, $first->quantity, $first->unit_price]);
        $this->assertSame(['Premium hosting', '99.00', $product->id], [$second->name, $second->unit_price, $second->product_id]);
    }

    public function test_blank_rows_are_ignored(): void
    {
        $user = User::factory()->create();
        $payload = $this->recurringPayload($this->customerFor($user));
        $payload['items'][] = ['product_id' => '', 'name' => '', 'description' => '', 'unit' => '', 'quantity' => '', 'unit_price' => ''];

        $this->actingAs($user)->post(route('recurring-invoices.store'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, RecurringInvoice::sole()->items()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'name missing' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'customer missing' => [['customer_id' => ''], 'customer_id'],
            'customer does not exist' => [['customer_id' => 999999], 'customer_id'],
            'frequency missing' => [['frequency' => ''], 'frequency'],
            'frequency unknown' => [['frequency' => 'daily'], 'frequency'],
            'start date missing' => [['start_date' => ''], 'start_date'],
            'start date malformed' => [['start_date' => '31/01/2026'], 'start_date'],
            'start date before today' => [['start_date' => '2026-01-30'], 'start_date'],
            'end date before start date' => [['end_date' => '2026-01-30'], 'end_date'],
            'end date malformed' => [['end_date' => 'next year'], 'end_date'],
            'payment terms missing' => [['payment_terms_days' => ''], 'payment_terms_days'],
            'payment terms negative' => [['payment_terms_days' => '-1'], 'payment_terms_days'],
            'payment terms too long' => [['payment_terms_days' => '366'], 'payment_terms_days'],
            'payment terms not a whole number' => [['payment_terms_days' => '1.5'], 'payment_terms_days'],
            'no lines' => [['items' => []], 'items'],
            'line without a name or product' => [['items' => [['quantity' => '1', 'unit_price' => '10']]], 'items.0.name'],
            'line without a quantity' => [['items' => [['name' => 'X', 'unit_price' => '10']]], 'items.0.quantity'],
            'line with zero quantity' => [['items' => [['name' => 'X', 'quantity' => '0', 'unit_price' => '10']]], 'items.0.quantity'],
            'line with a negative price' => [['items' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '-1']]], 'items.0.unit_price'],
            'line with three decimals' => [['items' => [['name' => 'X', 'quantity' => '1', 'unit_price' => '1.234']]], 'items.0.unit_price'],
            'discount above the subtotal' => [['discount_amount' => '300.01'], 'discount_amount'],
            'discount negative' => [['discount_amount' => '-1'], 'discount_amount'],
            'tax above 100' => [['tax_rate' => '100.01'], 'tax_rate'],
            'tax label too long' => [['tax_label' => str_repeat('a', 31)], 'tax_label'],
            'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user);

        $this->actingAs($user)->from(route('recurring-invoices.create'))
            ->post(route('recurring-invoices.store'), $this->recurringPayload($customer, overrides: $overrides))
            ->assertRedirect(route('recurring-invoices.create'))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, RecurringInvoice::count());
    }

    public function test_an_end_date_equal_to_the_start_date_is_allowed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('recurring-invoices.store'), $this->recurringPayload($this->customerFor($user), overrides: [
            'end_date' => '2026-01-31',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-01-31', RecurringInvoice::sole()->end_date->toDateString());
    }

    public function test_inactive_products_cannot_be_used(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->ownedBy($user)->inactive()->create();

        $this->actingAs($user)->post(route('recurring-invoices.store'), $this->recurringPayload($this->customerFor($user), [
            ['product_id' => $product->id, 'quantity' => '1'],
        ]))->assertSessionHasErrors(['items.0.product_id' => 'This product is inactive and cannot be added to an invoice.']);

        $this->assertSame(0, RecurringInvoice::count());
    }

    public function test_server_controlled_fields_cannot_be_tampered_with(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->post(route('recurring-invoices.store'), $this->recurringPayload($this->customerFor($user), overrides: [
            'business_id' => $this->businessOf($victim)->id,
            'created_by' => $victim->id,
            'status' => 'cancelled',
            'next_occurrence_on' => '2020-01-01',
            'last_occurrence_on' => '2020-01-01',
            'last_generation_error' => 'Forged',
        ]))->assertSessionHasNoErrors();

        $recurring = RecurringInvoice::sole();
        $this->assertSame($this->businessOf($user)->id, $recurring->business_id);
        $this->assertSame($user->id, $recurring->created_by);
        $this->assertSame(RecurringInvoiceStatus::Active, $recurring->status);
        $this->assertSame('2026-01-31', $recurring->next_occurrence_on->toDateString());
        $this->assertNull($recurring->last_occurrence_on);
        $this->assertNull($recurring->last_generation_error);
        $this->assertSame(0, $this->businessOf($victim)->recurringInvoices()->count());
    }

    public function test_users_can_edit_everything_before_the_first_invoice(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $newCustomer = $this->customerFor($user);

        $this->actingAs($user)->get(route('recurring-invoices.edit', $recurring))
            ->assertOk()
            ->assertSee('Website maintenance')
            ->assertSee('RM 300.00');

        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring), $this->recurringPayload($newCustomer, [
            ['name' => 'Retainer', 'quantity' => '2', 'unit_price' => '500.00'],
            ['name' => 'Hosting', 'quantity' => '1', 'unit_price' => '50.00'],
        ], [
            'name' => 'Renamed',
            'frequency' => 'weekly',
            'start_date' => '2026-02-02',
            'end_date' => '2026-06-30',
            'payment_terms_days' => '7',
        ]))->assertRedirect(route('recurring-invoices.show', $recurring))
            ->assertSessionHas('status', 'Recurring invoice updated. Invoices already generated are unchanged.');

        $recurring->refresh();
        $this->assertSame('Renamed', $recurring->name);
        $this->assertSame($newCustomer->id, $recurring->customer_id);
        $this->assertSame(RecurringFrequency::Weekly, $recurring->frequency);
        $this->assertSame('2026-02-02', $recurring->start_date->toDateString());
        $this->assertSame('2026-02-02', $recurring->next_occurrence_on->toDateString());
        $this->assertSame('2026-06-30', $recurring->end_date->toDateString());
        $this->assertSame(7, $recurring->payment_terms_days);
        $this->assertSame(['Retainer', 'Hosting'], $recurring->items()->pluck('name')->all());
        $this->assertSame([1, 2], $recurring->items()->pluck('position')->all());
    }

    public function test_an_unchanged_start_date_in_the_past_stays_valid_until_something_is_generated(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        $this->travelTo('2026-02-10 10:00:00');

        // Keeping the original start date is fine; choosing another past date is not.
        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring),
            $this->recurringPayload($recurring->customer, overrides: ['start_date' => '2026-01-31', 'name' => 'Kept']))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring),
            $this->recurringPayload($recurring->customer, overrides: ['start_date' => '2026-02-01']))
            ->assertSessionHasErrors('start_date');

        $this->assertSame('Kept', $recurring->fresh()->name);
        $this->assertSame('2026-01-31', $recurring->fresh()->next_occurrence_on->toDateString());
    }

    public function test_the_start_date_and_frequency_are_fixed_after_the_first_invoice(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        app(RecurringInvoiceService::class)->generateNext($this->businessOf($user), $recurring);

        $this->actingAs($user)->get(route('recurring-invoices.edit', $recurring))
            ->assertOk()
            ->assertSee('can’t be changed once an invoice has been generated');

        // Submitted schedule fields are ignored; everything else is saved.
        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring), $this->recurringPayload($recurring->customer, overrides: [
            'name' => 'Still editable',
            'frequency' => 'yearly',
            'start_date' => '2030-01-01',
        ]))->assertSessionHasNoErrors();

        $recurring->refresh();
        $this->assertSame('Still editable', $recurring->name);
        $this->assertSame(RecurringFrequency::Monthly, $recurring->frequency);
        $this->assertSame('2026-01-31', $recurring->start_date->toDateString());
        $this->assertSame('2026-02-28', $recurring->next_occurrence_on->toDateString());
    }

    public function test_the_end_date_cannot_be_moved_before_an_invoice_already_generated(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user);
        app(RecurringInvoiceService::class)->generateNext($this->businessOf($user), $recurring);

        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring),
            $this->recurringPayload($recurring->customer, overrides: ['end_date' => '2026-01-30']))
            ->assertSessionHasErrors('end_date');
        $this->actingAs($user)->put(route('recurring-invoices.update', $recurring),
            $this->recurringPayload($recurring->customer, overrides: ['end_date' => '2026-01-31']))
            ->assertSessionHasNoErrors();

        $this->assertTrue($recurring->fresh()->isFinished());
    }

    public function test_the_list_and_page_show_the_schedule_amount_and_state(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, $this->customerFor($user, ['name' => 'Aisyah Rahman']), overrides: [
            'start_date' => '2026-02-15', 'end_date' => '2026-12-15', 'tax_label' => 'SST', 'tax_rate' => '8',
        ]);

        $this->actingAs($user)->get(route('recurring-invoices.index'))
            ->assertOk()
            ->assertSee('Monthly website maintenance')
            ->assertSee('Aisyah Rahman')
            ->assertSee('Monthly')
            ->assertSee('15 Feb 2026')
            ->assertSee('RM 324.00')
            ->assertSee('Active')
            ->assertDontSee('data-due-count', false);

        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertOk()
            ->assertSee('Monthly, from 15 Feb 2026')
            ->assertSee('until 15 Dec 2026 (inclusive)')
            ->assertSee('Due 14 days after the invoice date')
            ->assertSee('SST (8.00%)')
            ->assertSee('RM 324.00')
            ->assertSee('No invoices have been generated yet.')
            ->assertDontSee('data-generate-now', false);
    }

    public function test_values_are_escaped(): void
    {
        $user = User::factory()->create();
        $recurring = $this->recurringFor($user, overrides: ['name' => '<script>alert(1)</script>', 'notes' => "<b>bold</b>\nline two"]);

        $this->actingAs($user)->get(route('recurring-invoices.show', $recurring))
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;bold&lt;/b&gt;<br />', false);
        $this->actingAs($user)->get(route('recurring-invoices.index'))->assertDontSee('<script>alert(1)</script>', false);
    }
}
