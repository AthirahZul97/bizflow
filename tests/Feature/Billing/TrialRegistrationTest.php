<?php

namespace Tests\Feature\Billing;

use App\Billing\AccessResolver;
use App\Billing\Entitlements;
use App\Billing\EntitlementService;
use App\Billing\SubscriptionNotice;
use App\Enums\AccessState;
use App\Enums\Entitlement;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BusinessRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrialRegistrationTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aisha Rahman',
            'business_name' => 'Rahman Trading',
            'email' => 'aisha@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ], $overrides);
    }

    public function test_registering_starts_one_14_day_trial_for_the_new_business(): void
    {
        $this->post(route('register'), $this->payload())->assertRedirect(route('dashboard'));

        $business = Business::query()->where('name', 'Rahman Trading')->sole();
        $subscription = $business->subscriptions()->with('plan')->sole();

        $this->assertSame('trial', $subscription->plan->code);
        $this->assertTrue($subscription->isCurrent());
        $this->assertSame('2026-10-24 12:00:00', $subscription->trial_ends_at->toDateTimeString());
        $this->assertSame(User::query()->where('email', 'aisha@example.com')->sole()->getKey(), $subscription->created_by);
    }

    public function test_the_new_owner_lands_on_a_dashboard_with_the_trial_banner(): void
    {
        $this->post(route('register'), $this->payload());

        $this->get(route('dashboard'))->assertOk()->assertSee('Free trial: 14 days left');
    }

    public function test_a_failed_trial_rolls_back_the_whole_registration(): void
    {
        DB::table('plans')->where('code', 'trial')->delete();

        $this->post(route('register'), $this->payload())->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, User::query()->where('email', 'aisha@example.com')->count());
        $this->assertSame(0, Business::query()->where('name', 'Rahman Trading')->count());
        $this->assertGuest();
    }

    public function test_a_retired_trial_plan_stops_registration_rather_than_skipping_the_trial(): void
    {
        Plan::query()->where('code', 'trial')->update(['is_active' => false]);

        $this->post(route('register'), $this->payload());

        $this->assertSame(0, User::query()->where('email', 'aisha@example.com')->count());
    }

    public function test_the_newest_active_trial_version_is_used(): void
    {
        Plan::factory()->create([
            'code' => 'trial', 'version' => 2, 'trial_days' => 30,
            'entitlements' => ['customers.max' => 3, 'invoices.email' => true],
        ]);

        $this->post(route('register'), $this->payload());

        $subscription = Business::query()->where('name', 'Rahman Trading')->sole()->subscriptions()->with('plan')->sole();
        $this->assertSame(2, $subscription->plan->version);
        $this->assertSame('2026-11-09 12:00:00', $subscription->trial_ends_at->toDateTimeString());
    }

    public function test_two_registrations_get_independent_trials(): void
    {
        $this->post(route('register'), $this->payload());
        auth()->logout();
        $this->travelTo('2026-10-12 12:00:00');
        $this->post(route('register'), $this->payload(['email' => 'second@example.com', 'business_name' => 'Second Co']));

        $ends = Subscription::query()->orderBy('id')->get()->map(fn ($s) => $s->trial_ends_at->toDateString())->all();

        $this->assertSame(['2026-10-24', '2026-10-26'], $ends);
    }

    public function test_the_trial_has_trial_limits_while_it_lasts(): void
    {
        $user = app(BusinessRegistration::class)->register([
            'name' => 'T', 'email' => 'trial2@example.com', 'password' => 'secret-pass-1', 'business_name' => 'Trial Two',
        ]);
        $business = $this->businessOf($user);
        Customer::factory()->count(5)->create(['business_id' => $business->getKey()]);

        $e = app(EntitlementService::class)->fresh($business);

        $this->assertSame(AccessState::Full, $e->access());
        $this->assertSame(200, $e->limit(Entitlement::Customers));
        $this->assertSame(195, $e->remaining(Entitlement::Customers));
    }

    public function test_a_trial_that_ends_makes_the_account_read_only_until_a_plan_is_chosen(): void
    {
        $this->post(route('register'), $this->payload());
        $this->travelTo('2026-10-24 12:00:00');

        $this->post(route('customers.store'), ['name' => 'Too Late'])->assertForbidden();
        $this->get(route('customers.index'))->assertOk();

        $this->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()])->assertRedirect(route('billing.show'));
        $this->post(route('customers.store'), ['name' => 'On Free Plan'])->assertRedirect();
        $this->assertSame(1, Customer::query()->where('name', 'On Free Plan')->count());
    }

    // ---- the banner texts -----------------------------------------------------------------

    private function notice(array $attributes, ?CarbonImmutable $now = null): ?SubscriptionNotice
    {
        $now ??= CarbonImmutable::parse('2026-10-10 12:00:00');
        $subscription = (new Subscription)->forceFill($attributes + ['status' => 'active', 'cancel_at_period_end' => false]);
        $subscription->setRelation('plan', Plan::factory()->make());
        $resolved = (new AccessResolver)->resolve($subscription, $now);

        return SubscriptionNotice::for(new Entitlements(new Business, $resolved, $now));
    }

    public function test_no_banner_for_an_ordinary_full_subscription(): void
    {
        $this->assertNull($this->notice([]));
        $this->assertNull($this->notice(['current_period_ends_at' => '2026-12-01 00:00:00']));
    }

    public function test_a_trial_banner_is_info_then_warning_in_the_last_three_days(): void
    {
        $info = $this->notice(['trial_ends_at' => '2026-10-20 12:00:00']);
        $warning = $this->notice(['trial_ends_at' => '2026-10-13 11:00:00']);
        $today = $this->notice(['trial_ends_at' => '2026-10-10 18:00:00']);

        $this->assertSame('info', $info->level);
        $this->assertStringContainsString('10 days left', $info->message);
        $this->assertSame('warning', $warning->level);
        $this->assertStringContainsString('3 days left', $warning->message);
        $this->assertStringContainsString('ends today', $today->message);
    }

    public function test_a_trial_with_one_day_left_says_so(): void
    {
        $this->assertStringContainsString('1 day left', $this->notice(['trial_ends_at' => '2026-10-11 12:00:00'])->message);
    }

    public function test_the_read_only_banners_explain_why_and_reassure_about_data(): void
    {
        $ended = $this->notice(['trial_ends_at' => '2026-10-01 00:00:00']);
        $expired = $this->notice(['current_period_ends_at' => '2026-09-01 00:00:00']);
        $none = SubscriptionNotice::for(new Entitlements(new Business, (new AccessResolver)->resolve(null), CarbonImmutable::now()));

        $this->assertSame('danger', $ended->level);
        $this->assertStringContainsString('free trial has ended', $ended->message);
        $this->assertStringContainsString('subscription has ended', $expired->message);
        $this->assertStringContainsString('no active subscription', $none->message);
        foreach ([$ended, $expired, $none] as $notice) {
            $this->assertStringContainsString('data is safe', $notice->message);
        }
    }

    public function test_the_grace_banner_gives_the_date_access_becomes_read_only(): void
    {
        $notice = $this->notice(['current_period_ends_at' => '2026-10-08 12:00:00', 'grace_ends_at' => '2026-10-15 12:00:00']);

        $this->assertSame('warning', $notice->level);
        $this->assertStringContainsString('15 Oct 2026', $notice->message);
        $this->assertStringContainsString('full access', $notice->message);
    }

    public function test_a_scheduled_cancellation_is_announced(): void
    {
        $notice = $this->notice(['cancel_at_period_end' => true, 'current_period_ends_at' => '2026-10-30 12:00:00']);

        $this->assertSame('warning', $notice->level);
        $this->assertStringContainsString('30 Oct 2026', $notice->message);
    }
}
