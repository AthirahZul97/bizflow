<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureSubscriptionWritable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\TestCase;

/**
 * Every route the application has, sorted into the category it belongs to, with the middleware and
 * authorization each category must carry. A new route that fits none of the lists fails here, so
 * it is categorized on purpose and cannot quietly arrive without authentication, a business or the
 * read-only rule.
 *
 * The categories are not interchangeable: guest and recovery routes must NOT sit behind the
 * business and subscription middleware (a visitor has neither).
 */
class RouteInventoryTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private const FRAMEWORK = ['up'];

    private const PUBLIC = ['/', 'health'];

    /** Reachable only when signed out (the `guest` middleware). */
    private const GUEST_ONLY = ['register', 'login', 'forgot-password', 'reset-password/{token}', 'reset-password'];

    /** Signed in, but about the account rather than a business. */
    private const AUTHENTICATED_NO_BUSINESS = ['logout', 'verify-email', 'verify-email/{id}/{hash}', 'email/verification-notification'];

    /**
     * @return list<RoutingRoute>
     */
    private function routes(): array
    {
        return array_values(iterator_to_array(Route::getRoutes()->getRoutes()));
    }

    private function category(RoutingRoute $route): string
    {
        return match (true) {
            in_array($route->uri(), self::FRAMEWORK, true) => 'framework',
            in_array($route->uri(), self::PUBLIC, true) => 'public',
            in_array($route->uri(), self::GUEST_ONLY, true) => 'guest',
            in_array($route->uri(), self::AUTHENTICATED_NO_BUSINESS, true) => 'account',
            default => 'business',
        };
    }

    /**
     * @return list<string>
     */
    private function middleware(RoutingRoute $route): array
    {
        return array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
    }

    private function position(RoutingRoute $route, string $middleware): int|false
    {
        foreach ($this->middleware($route) as $index => $name) {
            if ($name === $middleware || str_starts_with($name, $middleware.':')) {
                return $index;
            }
        }

        return false;
    }

    private function describe(RoutingRoute $route): string
    {
        return implode('|', array_diff($route->methods(), ['HEAD'])).' /'.ltrim($route->uri(), '/');
    }

    /**
     * @return list<array{RoutingRoute, string}>
     */
    private function inCategory(string $category): array
    {
        return array_map(fn ($r) => [$r, $this->describe($r)], array_values(array_filter($this->routes(), fn ($r) => $this->category($r) === $category)));
    }

    // ---- the inventory itself --------------------------------------------------------------------

    public function test_the_lists_name_only_routes_that_exist(): void
    {
        $uris = array_map(fn ($r) => $r->uri(), $this->routes());

        foreach ([...self::FRAMEWORK, ...self::PUBLIC, ...self::GUEST_ONLY, ...self::AUTHENTICATED_NO_BUSINESS] as $uri) {
            $this->assertContains($uri, $uris, "{$uri} is listed but is not a route");
        }
    }

    public function test_there_are_no_routes_from_outside_the_application_except_the_liveness_check(): void
    {
        foreach ($this->routes() as $route) {
            $action = $route->getActionName();

            $this->assertTrue(
                str_starts_with($action, 'App\\Http\\Controllers\\') || $route->uri() === '/' || $route->uri() === 'up',
                $this->describe($route).' is served by '.$action,
            );
        }

        $this->assertNull(Route::getRoutes()->getByName('storage.local'), 'the unused local-disk file server is gone');
        $this->assertNull(Route::getRoutes()->getByName('storage.local.upload'));
    }

    public function test_the_application_has_the_expected_number_of_business_routes(): void
    {
        $this->assertGreaterThan(70, count($this->inCategory('business')), 'a sanity floor: the categories still find the application\'s routes');
    }

    // ---- public and framework routes --------------------------------------------------------------

    public function test_public_routes_need_no_login_and_are_not_business_routes(): void
    {
        foreach ($this->inCategory('public') as [$route, $label]) {
            $this->assertFalse($this->position($route, 'auth'), $label);
            $this->assertFalse($this->position($route, 'business'), $label);
            $this->assertFalse($this->position($route, 'subscription.writable'), $label);
            $this->assertSame(['GET'], array_values(array_diff($route->methods(), ['HEAD'])), "{$label} is read-only");
        }

        // /health is outside the web group on purpose (no session, cookie or CSRF, and a limiter that
        // does not use the database), so a failing session or cache store cannot break the probe.
        $health = Route::getRoutes()->getByName('health');
        $this->assertNotFalse($this->position($health, 'health.limit'));
        $this->assertFalse($this->position($health, 'web'));
        $this->assertSame(['health.limit'], $this->middleware($health));
    }

    // ---- guest-only routes ------------------------------------------------------------------------

    public function test_guest_routes_are_guest_only_and_never_behind_business_or_subscription_middleware(): void
    {
        foreach ($this->inCategory('guest') as [$route, $label]) {
            $this->assertNotFalse($this->position($route, 'guest'), "{$label} is for signed-out visitors");
            $this->assertFalse($this->position($route, 'auth'), $label);
            $this->assertFalse($this->position($route, 'business'), "{$label} must not require a business");
            $this->assertFalse($this->position($route, 'subscription.writable'), "{$label} must not require a subscription");
        }
    }

    public function test_the_gates_sit_on_the_routes_they_protect(): void
    {
        foreach ($this->inCategory('guest') as [$route, $label]) {
            $isRegistration = $route->uri() === 'register';
            $isRecovery = in_array($route->uri(), ['forgot-password', 'reset-password/{token}', 'reset-password'], true);

            $this->assertSame($isRegistration, $this->position($route, 'registration.open') !== false, $label);
            $this->assertSame($isRecovery, $this->position($route, 'email.flows') !== false, $label);
        }

        foreach ($this->inCategory('account') as [$route, $label]) {
            $this->assertSame($route->uri() !== 'logout', $this->position($route, 'email.flows') !== false, $label);
        }

        $loginPost = collect($this->routes())->first(fn ($r) => $r->uri() === 'login' && in_array('POST', $r->methods(), true));
        $this->assertNotFalse($this->position($loginPost, 'throttle'));
        $this->assertContains('throttle:login-ip', $this->middleware($loginPost));

        $verify = Route::getRoutes()->getByName('verification.verify');
        $this->assertNotFalse($this->position($verify, 'signed'));
    }

    public function test_registration_and_recovery_posts_are_rate_limited(): void
    {
        foreach ($this->inCategory('guest') as [$route, $label]) {
            if (in_array('POST', $route->methods(), true)) {
                $this->assertNotFalse($this->position($route, 'throttle'), "{$label} is rate limited");
            }
        }

        foreach (['verification.verify', 'verification.send'] as $name) {
            $this->assertNotFalse($this->position(Route::getRoutes()->getByName($name), 'throttle'), $name);
        }
    }

    // ---- account routes (signed in, no business needed) ------------------------------------------

    public function test_account_routes_need_login_but_not_a_business(): void
    {
        foreach ($this->inCategory('account') as [$route, $label]) {
            $this->assertNotFalse($this->position($route, 'auth'), $label);
            $this->assertFalse($this->position($route, 'business'), "{$label}: an account without a business can still log out and verify");
            $this->assertFalse($this->position($route, 'subscription.writable'), $label);
            $this->assertFalse($this->position($route, 'guest'), $label);
        }
    }

    // ---- business routes ---------------------------------------------------------------------------

    public function test_business_routes_run_login_then_business_then_the_read_only_rule_in_that_order(): void
    {
        foreach ($this->inCategory('business') as [$route, $label]) {
            $auth = $this->position($route, 'auth');
            $business = $this->position($route, 'business');
            $writable = $this->position($route, 'subscription.writable');

            $this->assertNotFalse($auth, "{$label} needs login");
            $this->assertNotFalse($business, "{$label} needs a business");
            $this->assertNotFalse($writable, "{$label} needs the read-only rule");
            $this->assertTrue($auth < $business && $business < $writable, "{$label}: auth, then business, then subscription.writable");
            $this->assertFalse($this->position($route, 'guest'), $label);
        }
    }

    public function test_business_routes_with_a_record_in_the_url_carry_a_policy_check_after_the_read_only_rule(): void
    {
        foreach ($this->inCategory('business') as [$route, $label]) {
            if ($route->parameterNames() === []) {
                continue;
            }

            $can = $this->position($route, 'can');

            $this->assertNotFalse($can, "{$label} has a record in the URL, so a policy must authorize it");
            $this->assertGreaterThan($this->position($route, 'subscription.writable'), $can, $label);
        }
    }

    public function test_mail_sending_uploading_and_generating_routes_are_rate_limited(): void
    {
        foreach (['invoices.email.store', 'recurring-invoices.generate', 'expense-receipts.store', 'expense-receipts.confirm', 'expense-receipts.retry', 'billing.change', 'billing.cancel', 'billing.resume', 'business.profile.update'] as $name) {
            $this->assertNotFalse($this->position(Route::getRoutes()->getByName($name), 'throttle'), "{$name} is rate limited");
        }
    }

    public function test_the_email_routes_are_where_verification_can_be_required(): void
    {
        foreach (['invoices.email.create', 'invoices.email.store'] as $name) {
            $this->assertNotFalse($this->position(Route::getRoutes()->getByName($name), 'verified.required'), $name);
        }
    }

    public function test_every_unsafe_business_route_is_covered_by_the_read_only_rule_except_the_billing_allow_list(): void
    {
        $this->assertSame(['billing.change', 'billing.cancel', 'billing.resume'], EnsureSubscriptionWritable::READ_ONLY_ALLOWED_ROUTES);

        $unsafe = array_filter($this->inCategory('business'), fn ($entry) => count(array_diff($entry[0]->methods(), ['HEAD', 'GET'])) > 0);
        $this->assertNotEmpty($unsafe);

        foreach ($unsafe as [$route, $label]) {
            $this->assertNotFalse($this->position($route, 'subscription.writable'), $label);
        }

        // The allow-list names only billing routes that exist, and only unsafe ones.
        foreach (EnsureSubscriptionWritable::READ_ONLY_ALLOWED_ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertStringStartsWith('billing.', $name);
            $this->assertNotEmpty(array_diff($route->methods(), ['HEAD', 'GET']));
        }
    }

    // ---- behaviour: guests ---------------------------------------------------------------------------

    public function test_guests_are_sent_to_login_by_every_authenticated_route(): void
    {
        foreach (array_merge($this->inCategory('business'), $this->inCategory('account')) as [$route, $label]) {
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '999999', $route->uri()), '/');

            $response = $this->call($method, $uri);

            $this->assertTrue(
                $response->isRedirect(route('login')) || $response->getStatusCode() === 401,
                "{$label} answered a guest with {$response->getStatusCode()}",
            );
        }
    }

    // ---- behaviour: forged ids -------------------------------------------------------------------------

    public function test_a_forged_record_id_is_a_404_on_every_route_that_takes_one(): void
    {
        $user = User::factory()->create();

        foreach ($this->inCategory('business') as [$route, $label]) {
            if ($route->parameterNames() === []) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '999999', $route->uri()), '/');

            $this->actingAs($user)->call($method, $uri)->assertNotFound($label);
        }
    }

    // ---- behaviour: read-only business ----------------------------------------------------------------

    public function test_a_read_only_business_can_read_and_is_refused_every_write_except_billing(): void
    {
        $user = User::factory()->create();
        $this->makeReadOnly($user);
        $allowed = EnsureSubscriptionWritable::READ_ONLY_ALLOWED_ROUTES;

        foreach ($this->inCategory('business') as [$route, $label]) {
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '999999', $route->uri()), '/');
            $status = $this->actingAs($user)->call($method, $uri, $method === 'GET' ? [] : ['_nothing' => 1])->getStatusCode();

            if ($method === 'GET') {
                // Reading keeps working: a missing record is a 404, never the read-only 403 or a crash.
                $this->assertNotSame(500, $status, $label);
                if ($route->parameterNames() !== []) {
                    $this->assertSame(404, $status, $label);
                }

                continue;
            }

            if (in_array($route->getName(), $allowed, true)) {
                $this->assertNotSame(403, $status, "{$label} is a billing exception and must stay reachable");
                $this->assertNotSame(500, $status, $label);

                continue;
            }

            $this->assertSame(403, $status, "{$label} must be refused while read-only, before the record is even looked up");
        }
    }

    public function test_a_read_only_business_still_sees_its_main_pages(): void
    {
        $user = User::factory()->create();
        $this->makeReadOnly($user);

        foreach (['dashboard', 'customers.index', 'products.index', 'invoices.index', 'expenses.index', 'recurring-invoices.index', 'expense-receipts.index', 'reports.summary', 'billing.show', 'billing.plans'] as $name) {
            $this->assertSame(200, $this->actingAs($user)->get(route($name))->getStatusCode(), $name);
        }
    }
}
