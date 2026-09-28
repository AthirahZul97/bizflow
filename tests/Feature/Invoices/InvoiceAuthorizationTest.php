<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceAuthorizationTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    /**
     * Create an invoice owned by $owner in the given state.
     */
    private function invoiceIn(string $state, ?User $owner = null): Invoice
    {
        $factory = Invoice::factory();

        if ($state !== 'draft') {
            $factory = $factory->{$state}();
        }

        return $factory->create($owner ? ['user_id' => $owner->id] : []);
    }

    /**
     * Every invoice route: [method, route name, needs an invoice].
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function allRoutes(): array
    {
        return [
            'index' => ['get', 'invoices.index', false],
            'create' => ['get', 'invoices.create', false],
            'store' => ['post', 'invoices.store', false],
            'show' => ['get', 'invoices.show', true],
            'edit' => ['get', 'invoices.edit', true],
            'update' => ['put', 'invoices.update', true],
            'delete confirmation' => ['get', 'invoices.delete', true],
            'destroy' => ['delete', 'invoices.destroy', true],
            'issue' => ['post', 'invoices.issue', true],
            'mark paid' => ['post', 'invoices.mark-paid', true],
            'mark unpaid' => ['post', 'invoices.mark-unpaid', true],
            'cancel confirmation' => ['get', 'invoices.cancel.confirm', true],
            'cancel' => ['post', 'invoices.cancel', true],
        ];
    }

    #[DataProvider('allRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route, bool $needsInvoice): void
    {
        $invoice = $this->invoiceIn('draft');

        $url = $needsInvoice ? route($route, $invoice) : route($route);

        $this->{$method}($url)->assertRedirect(route('login'));
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    /**
     * Actions on another user's invoice, each in a state where the owner could
     * perform it, so the 404 can only come from the ownership check.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function ownedActions(): array
    {
        return [
            'show' => ['get', 'invoices.show', 'issued'],
            'edit' => ['get', 'invoices.edit', 'draft'],
            'update' => ['put', 'invoices.update', 'draft'],
            'delete confirmation' => ['get', 'invoices.delete', 'draft'],
            'destroy' => ['delete', 'invoices.destroy', 'draft'],
            'issue' => ['post', 'invoices.issue', 'draft'],
            'mark paid' => ['post', 'invoices.mark-paid', 'issued'],
            'mark unpaid' => ['post', 'invoices.mark-unpaid', 'paid'],
            'cancel confirmation' => ['get', 'invoices.cancel.confirm', 'issued'],
            'cancel' => ['post', 'invoices.cancel', 'issued'],
        ];
    }

    #[DataProvider('ownedActions')]
    public function test_another_users_invoice_is_not_found(string $method, string $route, string $state): void
    {
        $intruder = User::factory()->create();
        $invoice = $this->invoiceIn($state);
        $before = $invoice->fresh()->getAttributes();

        $payload = match ($route) {
            'invoices.update' => $this->invoicePayload($this->customerFor($intruder)),
            'invoices.mark-paid' => ['paid_at' => '2026-09-28'],
            default => [],
        };

        $response = $this->actingAs($intruder)->{$method}(route($route, $invoice), $payload);

        $response->assertNotFound();
        $response->assertDontSee($invoice->customer_name);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_another_users_invoice_is_not_found_even_with_an_invalid_payload(): void
    {
        $intruder = User::factory()->create();
        $draft = $this->invoiceIn('draft');
        $issued = $this->invoiceIn('issued');

        $this->actingAs($intruder)
            ->put(route('invoices.update', $draft), ['customer_id' => 'x', 'items' => 'nope', 'issue_date' => 'bad'])
            ->assertNotFound()
            ->assertSessionHasNoErrors();

        $this->actingAs($intruder)
            ->post(route('invoices.mark-paid', $issued), ['paid_at' => 'not-a-date'])
            ->assertNotFound()
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ownerPages(): array
    {
        return [
            'show draft' => ['invoices.show', 'draft'],
            'show issued' => ['invoices.show', 'issued'],
            'show paid' => ['invoices.show', 'paid'],
            'show cancelled' => ['invoices.show', 'cancelled'],
            'edit draft' => ['invoices.edit', 'draft'],
            'delete confirmation for draft' => ['invoices.delete', 'draft'],
            'cancel confirmation for issued' => ['invoices.cancel.confirm', 'issued'],
        ];
    }

    #[DataProvider('ownerPages')]
    public function test_owners_can_open_pages_allowed_for_the_invoice_state(string $route, string $state): void
    {
        $invoice = $this->invoiceIn($state);

        $this->actingAs($invoice->user)->get(route($route, $invoice))->assertOk();
    }

    /**
     * Owner actions the invoice's status does not allow.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function wrongStateActions(): array
    {
        return [
            'edit issued' => ['get', 'invoices.edit', 'issued'],
            'update issued' => ['put', 'invoices.update', 'issued'],
            'update paid' => ['put', 'invoices.update', 'paid'],
            'update cancelled' => ['put', 'invoices.update', 'cancelled'],
            'delete page for issued' => ['get', 'invoices.delete', 'issued'],
            'destroy issued' => ['delete', 'invoices.destroy', 'issued'],
            'destroy paid' => ['delete', 'invoices.destroy', 'paid'],
            'destroy cancelled' => ['delete', 'invoices.destroy', 'cancelled'],
            'issue twice' => ['post', 'invoices.issue', 'issued'],
            'issue cancelled' => ['post', 'invoices.issue', 'cancelled'],
            'mark draft paid' => ['post', 'invoices.mark-paid', 'draft'],
            'mark paid twice' => ['post', 'invoices.mark-paid', 'paid'],
            'mark cancelled paid' => ['post', 'invoices.mark-paid', 'cancelled'],
            'mark issued unpaid' => ['post', 'invoices.mark-unpaid', 'issued'],
            'mark draft unpaid' => ['post', 'invoices.mark-unpaid', 'draft'],
            'cancel draft' => ['post', 'invoices.cancel', 'draft'],
            'cancel paid' => ['post', 'invoices.cancel', 'paid'],
            'cancel twice' => ['post', 'invoices.cancel', 'cancelled'],
            'cancel page for draft' => ['get', 'invoices.cancel.confirm', 'draft'],
        ];
    }

    #[DataProvider('wrongStateActions')]
    public function test_owner_actions_not_allowed_in_the_current_state_are_forbidden(string $method, string $route, string $state): void
    {
        $invoice = $this->invoiceIn($state);
        $before = $invoice->fresh()->getAttributes();

        $payload = match ($route) {
            'invoices.update' => $this->invoicePayload($invoice->customer),
            'invoices.mark-paid' => ['paid_at' => '2026-09-28'],
            default => [],
        };

        $this->actingAs($invoice->user)->{$method}(route($route, $invoice), $payload)->assertForbidden();

        $this->assertModelExists($invoice);
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_forbidden_message_explains_the_rule(): void
    {
        $invoice = $this->invoiceIn('issued');

        $this->actingAs($invoice->user)->get(route('invoices.edit', $invoice))
            ->assertForbidden()
            ->assertSee('Only draft invoices can be edited.');
    }

    public function test_missing_invoice_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/invoices/999999')->assertNotFound();
    }
}
