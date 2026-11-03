<?php

namespace App\Console\Commands;

use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Operator tool: put a business on a plan immediately. This is how paid plans (and Legacy) are
 * assigned until a payment provider exists. It goes through SubscriptionService, so the old
 * subscription is ended as replaced and history is kept.
 */
class AssignBusinessPlan extends Command
{
    protected $signature = 'billing:assign
                            {business : The business ID}
                            {plan : A plan code (its newest active version), or code:version}';

    protected $description = 'Assign a plan to a business immediately (operator only; the only way to start a paid plan before a payment provider exists)';

    public function handle(SubscriptionService $subscriptions): int
    {
        $business = ctype_digit((string) $this->argument('business'))
            ? Business::query()->find((int) $this->argument('business'))
            : null;

        if ($business === null) {
            $this->error('No business with that ID.');

            return self::FAILURE;
        }

        $plan = $this->resolvePlan((string) $this->argument('plan'));

        if ($plan === null) {
            $this->error('No active plan matches that code (use code or code:version).');

            return self::FAILURE;
        }

        try {
            $subscription = $subscriptions->activate($business, $plan);
        } catch (SubscriptionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Business {$business->getKey()} ({$business->name}) is now on {$plan->name} (plan {$plan->code} v{$plan->version}).");

        if ($subscription->current_period_ends_at !== null) {
            $this->line('Current period ends '.$subscription->current_period_ends_at->toDateTimeString().'.');
        }

        return self::SUCCESS;
    }

    private function resolvePlan(string $argument): ?Plan
    {
        [$code, $version] = array_pad(explode(':', $argument, 2), 2, null);

        if ($version === null) {
            return Plan::latestActive($code);
        }

        if (! ctype_digit($version)) {
            return null;
        }

        return Plan::query()->active()->where('code', $code)->where('version', (int) $version)->first();
    }
}
