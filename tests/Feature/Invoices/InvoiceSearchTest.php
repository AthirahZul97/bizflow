<?php

namespace Tests\Feature\Invoices;

use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceSearchTest extends TestCase
{
    use CreatesInvoices;
    use RefreshDatabase;

    public function test_invoices_can_be_searched_by_number(): void
    {
        $user = User::factory()->create();
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'First Customer']));
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'Second Customer']));

        $this->actingAs($user)->get(route('invoices.index', ['search' => 'inv-00002']))
            ->assertSee('Second Customer')
            ->assertDontSee('First Customer');
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function customerFields(): array
    {
        return [
            'customer name' => [['name' => 'Zainab Ali'], 'zainab'],
            'company name' => [['name' => 'Target Person', 'company_name' => 'Orchid Florist'], 'ORCHID'],
        ];
    }

    #[DataProvider('customerFields')]
    public function test_invoices_can_be_searched_by_copied_customer_details(array $customer, string $term): void
    {
        $user = User::factory()->create();
        $this->draftFor($user, $this->customerFor($user, $customer));
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Unrelated Person', 'company_name' => null]));

        $this->actingAs($user)->get(route('invoices.index', ['search' => $term]))
            ->assertSee($customer['name'])
            ->assertDontSee('Unrelated Person');
    }

    public function test_search_never_returns_another_users_invoices(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'Acme Mine']));
        $this->issuedFor($other, $this->customerFor($other, ['name' => 'Acme Theirs', 'company_name' => 'Acme']));

        $this->actingAs($user)->get(route('invoices.index', ['search' => 'Acme']))
            ->assertSee('Acme Mine')
            ->assertDontSee('Acme Theirs');

        $this->actingAs($user)->get(route('invoices.index', ['search' => 'INV-00001']))
            ->assertSee('Acme Mine')
            ->assertDontSee('Acme Theirs');
    }

    public function test_like_wildcards_and_escape_character_are_matched_literally(): void
    {
        $user = User::factory()->create();
        foreach (['Promo 50% Co', 'Promo 500 Co', 'Kit A_B', 'Kit AXB', 'Wow! Ltd', 'Wow Ltd'] as $name) {
            $this->draftFor($user, $this->customerFor($user, ['name' => $name]));
        }

        $this->actingAs($user)->get(route('invoices.index', ['search' => '50%']))
            ->assertSee('Promo 50% Co')->assertDontSee('Promo 500 Co');
        $this->actingAs($user)->get(route('invoices.index', ['search' => 'A_B']))
            ->assertSee('Kit A_B')->assertDontSee('Kit AXB');
        $this->actingAs($user)->get(route('invoices.index', ['search' => 'Wow!']))
            ->assertSee('Wow! Ltd')->assertDontSee('Wow Ltd');
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function statusFilters(): array
    {
        return [
            'draft' => ['draft', ['Draft Co']],
            'issued (includes overdue)' => ['issued', ['Issued Co', 'Overdue Co']],
            'overdue' => ['overdue', ['Overdue Co']],
            'paid' => ['paid', ['Paid Co']],
            'cancelled' => ['cancelled', ['Cancelled Co']],
        ];
    }

    #[DataProvider('statusFilters')]
    public function test_invoices_can_be_filtered_by_status(string $filter, array $expected): void
    {
        $user = User::factory()->create();
        $service = app(InvoiceService::class);
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Draft Co']));
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'Issued Co']));
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'Overdue Co']), ['due_date' => '2026-09-27']);
        $service->markPaid($this->issuedFor($user, $this->customerFor($user, ['name' => 'Paid Co']), ['due_date' => '2026-09-01']), '2026-09-02');
        $service->cancel($this->issuedFor($user, $this->customerFor($user, ['name' => 'Cancelled Co']), ['due_date' => '2026-09-01']));

        $response = $this->actingAs($user)->get(route('invoices.index', ['status' => $filter]));

        foreach (['Draft Co', 'Issued Co', 'Overdue Co', 'Paid Co', 'Cancelled Co'] as $name) {
            in_array($name, $expected, true) ? $response->assertSee($name) : $response->assertDontSee($name);
        }
    }

    public function test_search_and_status_filter_combine(): void
    {
        $user = User::factory()->create();
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'Web Issued']));
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Web Draft']));
        $this->issuedFor($user, $this->customerFor($user, ['name' => 'Print Issued']));

        $this->actingAs($user)->get(route('invoices.index', ['search' => 'Web', 'status' => 'issued']))
            ->assertSee('Web Issued')
            ->assertDontSee('Web Draft')
            ->assertDontSee('Print Issued');
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        $user = User::factory()->create();
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Visible Co']));

        $this->actingAs($user)->get(route('invoices.index', ['status' => 'deleted', 'search' => ['x']]))
            ->assertOk()
            ->assertSee('Visible Co');
    }

    public function test_invoices_are_sorted_by_newest_issue_date(): void
    {
        $user = User::factory()->create();
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Middle Co']), overrides: ['issue_date' => '2026-09-10']);
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Oldest Co']), overrides: ['issue_date' => '2026-09-01']);
        $this->draftFor($user, $this->customerFor($user, ['name' => 'Newest Co']), overrides: ['issue_date' => '2026-09-20']);

        $this->actingAs($user)->get(route('invoices.index'))
            ->assertSeeInOrder(['Newest Co', 'Middle Co', 'Oldest Co']);
    }

    public function test_invoices_are_paginated_and_links_keep_filters(): void
    {
        $user = User::factory()->create();
        $customer = $this->customerFor($user, ['name' => 'Repeat Customer']);
        foreach (range(1, 16) as $day) {
            $this->issuedFor($user, $customer, ['issue_date' => sprintf('2026-08-%02d', $day), 'due_date' => '2026-12-31']);
        }

        $this->actingAs($user)->get(route('invoices.index', ['search' => 'Repeat', 'status' => 'issued']))
            ->assertSee('INV-00016')
            ->assertDontSee('INV-00001<', false)
            ->assertSee('search=Repeat&status=issued&page=2');

        $this->actingAs($user)->get(route('invoices.index', ['search' => 'Repeat', 'status' => 'issued', 'page' => 2]))
            ->assertSee('INV-00001')
            ->assertDontSee('INV-00016');
    }

    public function test_empty_states(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('invoices.index'))
            ->assertSee("You haven't created any invoices yet.", false)
            ->assertSee('Create your first invoice');

        $this->draftFor($user);

        $this->actingAs($user)->get(route('invoices.index', ['status' => 'paid']))
            ->assertSee('No invoices match your search or filter.');
    }
}
