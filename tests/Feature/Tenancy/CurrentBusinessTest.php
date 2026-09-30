<?php

namespace Tests\Feature\Tenancy;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class CurrentBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_users_only_business(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertTrue(app(CurrentBusiness::class)->get()->is($this->businessOf($user)));
    }

    public function test_it_is_resolved_once_per_request(): void
    {
        $this->actingAs(User::factory()->create());
        $current = app(CurrentBusiness::class);

        DB::enableQueryLog();
        $first = $current->get();
        $second = $current->get();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame(1, $queries);
    }

    public function test_switching_the_authenticated_user_resolves_their_own_business(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $current = app(CurrentBusiness::class);

        $this->actingAs($alice);
        $this->assertTrue($current->get()->is($this->businessOf($alice)));

        $this->actingAs($bob);
        $this->assertTrue($current->get()->is($this->businessOf($bob)));
    }

    public function test_guests_have_no_current_business(): void
    {
        $this->expectException(AuthenticationException::class);

        app(CurrentBusiness::class)->get();
    }

    public function test_a_user_without_a_business_is_refused_but_can_still_log_out(): void
    {
        $user = User::factory()->withoutBusiness()->create();

        foreach (['dashboard', 'customers.index', 'invoices.index', 'reports.summary', 'business.profile.edit'] as $route) {
            $this->actingAs($user)->get(route($route))
                ->assertForbidden()
                ->assertSee('Your account isn’t linked to a business.');
        }

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_a_user_without_a_business_gets_an_exception_from_the_resolver(): void
    {
        $this->actingAs(User::factory()->withoutBusiness()->create());

        $this->expectException(AccessDeniedHttpException::class);

        app(CurrentBusiness::class)->get();
    }

    public function test_more_than_one_business_is_not_supported_yet(): void
    {
        $user = User::factory()->create();
        Business::factory()->withoutOwner()->create()->members()->attach($user, ['role' => BusinessRole::Owner->value]);
        $this->actingAs($user);

        $this->expectException(LogicException::class);

        app(CurrentBusiness::class)->get();
    }

    public function test_ownership_requires_the_record_in_the_current_business_and_the_resolved_member(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $ownCustomer = Customer::factory()->ownedBy($alice)->create();
        $foreignCustomer = Customer::factory()->ownedBy($bob)->create();
        $this->actingAs($alice);
        $current = app(CurrentBusiness::class);

        $this->assertTrue($current->owns($alice, $ownCustomer));
        $this->assertFalse($current->owns($alice, $foreignCustomer));
        // Asking on behalf of a different user than the one the business was resolved for is refused.
        $this->assertFalse($current->owns($bob, $ownCustomer));
    }
}
