<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Http\Requests\ChangePlanRequest;
use App\Models\Plan;
use App\Services\SubscriptionService;
use App\Support\CurrentBusiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The current business's subscription: what plan it is on, what it uses, its history, and
 * the few self-service changes (switching to Free, cancelling, resuming). There is no
 * business or subscription ID in any URL; it is always the current business, and every
 * action is authorized through BusinessPolicy (members view, only the owner manages).
 *
 * Paid plans are never activated here: there is no payment provider yet, so they show
 * "Contact us to upgrade" and are assigned by an operator (billing:assign).
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Current plan, access state, usage against limits and the subscription history.
     */
    public function show(): View
    {
        $business = $this->currentBusiness->get();
        Gate::authorize('viewBilling', $business);

        $entitlements = $this->entitlements->for($business);

        return view('billing.show', [
            'entitlements' => $entitlements,
            'subscription' => $entitlements->resolved()->subscription,
            'history' => $business->subscriptions()->with('plan')->orderByDesc('started_at')->orderByDesc('id')->get(),
            'usage' => $this->usage($entitlements),
            'canManage' => Gate::allows('manageBilling', $business),
        ]);
    }

    /**
     * Compare the plans a business can look at, with what each one's button does.
     */
    public function plans(): View
    {
        $business = $this->currentBusiness->get();
        Gate::authorize('viewBilling', $business);

        $entitlements = $this->entitlements->for($business);
        $subscription = $entitlements->resolved()->subscription;
        $current = $subscription?->plan;

        // The newest active version of each plan, minus the one-time Trial plan; the Legacy
        // plan only appears for the business that is on it.
        $plans = Plan::query()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->groupBy('code')->map(fn ($versions) => $versions->sortByDesc('version')->first())
            ->reject(fn (Plan $plan) => $plan->isTrial() || ($plan->isLegacy() && $current?->code !== $plan->code))
            ->values();

        return view('billing.plans', [
            'plans' => $plans,
            'entitlements' => $entitlements,
            'current' => $entitlements->plan(),
            'subscription' => $subscription,
            'actions' => $plans->mapWithKeys(fn (Plan $plan) => [$plan->code => $this->actionFor($plan, $entitlements)]),
            'canManage' => Gate::allows('manageBilling', $business),
            'catalogue' => Entitlement::cases(),
        ]);
    }

    /**
     * Switch to a Free plan (the only self-service change). From a running paid period this
     * is a downgrade that takes effect when the period ends.
     */
    public function change(ChangePlanRequest $request): RedirectResponse
    {
        // ChangePlanRequest has already authorized manageBilling and validated the plan id.
        $business = $this->currentBusiness->get();
        $plan = Plan::query()->active()->findOrFail($request->validated('plan_id'));

        $subscription = $this->subscriptions->changePlan($business, $plan, $request->user());

        return redirect()->route('billing.show')->with('status', $subscription->pending_plan_id !== null
            ? "You'll move to the {$plan->name} plan when your current period ends."
            : "You're now on the {$plan->name} plan.");
    }

    /**
     * Ask for confirmation before cancelling.
     */
    public function confirmCancel(): View|RedirectResponse
    {
        $business = $this->currentBusiness->get();
        Gate::authorize('manageBilling', $business);

        $subscription = $this->entitlements->for($business)->resolved()->subscription;

        if ($subscription === null || $subscription->cancel_at_period_end || $subscription->trial_ends_at !== null
            || $subscription->current_period_ends_at === null) {
            return redirect()->route('billing.show')->with('error', 'There is no paid subscription to cancel.');
        }

        return view('billing.cancel', ['subscription' => $subscription]);
    }

    /**
     * Stop the subscription renewing; access continues to the end of the period.
     */
    public function cancel(): RedirectResponse
    {
        $business = $this->currentBusiness->get();
        Gate::authorize('manageBilling', $business);

        $subscription = $this->subscriptions->cancelAtPeriodEnd($business);

        return redirect()->route('billing.show')
            ->with('status', 'Your subscription will end on '.$subscription->current_period_ends_at->format('d M Y').'.');
    }

    /**
     * Undo a scheduled cancellation or downgrade.
     */
    public function resume(): RedirectResponse
    {
        $business = $this->currentBusiness->get();
        Gate::authorize('manageBilling', $business);

        $this->subscriptions->resume($business);

        return redirect()->route('billing.show')->with('status', 'Your subscription will continue as it is.');
    }

    /**
     * What the plan's button does for this business: current, scheduled, a self-service
     * switch, or "contact us".
     */
    private function actionFor(Plan $plan, Entitlements $entitlements): string
    {
        $subscription = $entitlements->resolved()->subscription;

        if ($entitlements->plan()?->getKey() === $plan->getKey()) {
            return 'current';
        }

        if ($subscription?->pending_plan_id === $plan->getKey()) {
            return 'scheduled';
        }

        $onLegacy = (bool) $subscription?->plan->isLegacy();

        return $plan->isFree() && ! $plan->isLegacy() && ! $plan->isTrial() && ! $onLegacy ? 'switch' : 'contact';
    }

    /**
     * Every entitlement with what the business has and uses.
     *
     * @return list<array{entitlement: Entitlement, limit: int|null, used: int|null, remaining: int|null, included: bool, over: bool}>
     */
    private function usage(Entitlements $entitlements): array
    {
        $plan = $entitlements->plan();

        return array_map(function (Entitlement $entitlement) use ($entitlements, $plan) {
            if ($entitlement->isFlag()) {
                return [
                    'entitlement' => $entitlement,
                    'limit' => null,
                    'used' => null,
                    'remaining' => null,
                    'included' => $plan?->valueOf($entitlement) === true,
                    'over' => false,
                ];
            }

            $limit = $entitlements->limit($entitlement);
            $used = $entitlements->used($entitlement);

            return [
                'entitlement' => $entitlement,
                'limit' => $limit,
                'used' => $used,
                'remaining' => $entitlements->remaining($entitlement),
                'included' => $limit !== 0,
                'over' => $limit !== null && $used > $limit,
            ];
        }, Entitlement::cases());
    }
}
