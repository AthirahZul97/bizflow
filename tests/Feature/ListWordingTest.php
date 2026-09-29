<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four list pages use the same wording for their main actions.
 */
class ListWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_pages_use_consistent_new_filter_and_clear_wording(): void
    {
        $user = User::factory()->create();

        $pages = [
            'customers.index' => ['New customer', 'Create your first customer'],
            'products.index' => ['New product or service', 'Create your first product or service'],
            'invoices.index' => ['New invoice', 'Create your first invoice'],
            'expenses.index' => ['New expense', 'Record your first expense'],
        ];

        foreach ($pages as $route => [$new, $firstAction]) {
            $this->actingAs($user)->get(route($route))
                ->assertOk()
                ->assertSee($new)
                ->assertSee($firstAction)
                ->assertSee('>Filter</button>', false)
                ->assertDontSee('>Search</button>', false)
                ->assertDontSee('New item');

            $this->actingAs($user)->get(route($route, ['search' => 'zzz-no-match']))
                ->assertSee('Clear filters')
                ->assertDontSee('>Clear<', false)
                ->assertDontSee('Clear search');
        }
    }
}
