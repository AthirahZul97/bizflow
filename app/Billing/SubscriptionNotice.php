<?php

namespace App\Billing;

use App\Enums\AccessState;
use Carbon\CarbonImmutable;

/**
 * The message shown in the banner at the top of every page, if the subscription needs
 * attention: a trial in progress, a grace period, a scheduled end, or read-only access.
 * Read-only.
 */
final readonly class SubscriptionNotice
{
    /**
     * @param  'info'|'warning'|'danger'  $level  A Bootstrap alert colour.
     */
    public function __construct(
        public string $level,
        public string $message,
    ) {}

    public static function for(Entitlements $entitlements): ?self
    {
        $resolved = $entitlements->resolved();
        $now = $entitlements->evaluatedAt();
        $subscription = $resolved->subscription;

        if ($resolved->state === AccessState::ReadOnly) {
            return new self('danger', match ($resolved->reason) {
                'no_subscription' => 'This business has no active subscription, so it is read-only. Your data is safe and you can still view and download everything.',
                'trial_ended' => 'Your free trial has ended, so your account is read-only. Your data is safe and you can still view and download everything. Choose a plan to continue.',
                default => 'Your subscription has ended, so your account is read-only. Your data is safe and you can still view and download everything. Choose a plan to continue.',
            });
        }

        if ($resolved->state === AccessState::Grace && $resolved->graceEndsAt !== null) {
            return new self('warning', 'Your subscription period has ended. You have full access until '
                .self::date($resolved->graceEndsAt).', after which your account becomes read-only. Contact us to renew.');
        }

        if ($resolved->reason === 'trial' && $resolved->endsAt !== null) {
            $days = self::daysLeft($now, $resolved->endsAt);

            return new self($days <= 3 ? 'warning' : 'info', 'Free trial: '.($days <= 0 ? 'ends today' : ($days === 1 ? '1 day left' : "{$days} days left"))
                .' (until '.self::date($resolved->endsAt).'). After that your account becomes read-only unless you choose a plan.');
        }

        if ($subscription?->cancel_at_period_end && $resolved->endsAt !== null) {
            return new self('warning', 'Your subscription is set to end on '.self::date($resolved->endsAt)
                .', after which your account becomes read-only.');
        }

        return null;
    }

    private static function daysLeft(CarbonImmutable $now, CarbonImmutable $end): int
    {
        // Whole calendar days, so a trial ending this evening says "ends today", not "1 day left".
        return max(0, (int) $now->startOfDay()->diffInDays($end->startOfDay(), false));
    }

    private static function date(CarbonImmutable $date): string
    {
        return $date->format('d M Y');
    }
}
