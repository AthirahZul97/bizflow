<?php

namespace Tests\Unit\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class SubscriptionStatusTest extends TestCase
{
    public function test_only_the_approved_transitions_are_allowed(): void
    {
        $allowed = [
            'trialing>active', 'trialing>expired', 'trialing>replaced',
            'active>active', 'active>past_due', 'active>expired', 'active>replaced',
            'past_due>active', 'past_due>expired', 'past_due>replaced',
        ];

        foreach (SubscriptionStatus::cases() as $from) {
            foreach (SubscriptionStatus::cases() as $to) {
                $this->assertSame(
                    in_array("{$from->value}>{$to->value}", $allowed, true),
                    $from->canTransitionTo($to),
                    "{$from->value} to {$to->value}",
                );
            }
        }
    }

    public function test_expired_and_replaced_are_terminal(): void
    {
        $this->assertTrue(SubscriptionStatus::Expired->isTerminal());
        $this->assertTrue(SubscriptionStatus::Replaced->isTerminal());
        $this->assertFalse(SubscriptionStatus::Active->isTerminal());
        $this->assertFalse(SubscriptionStatus::PastDue->isTerminal());
        $this->assertFalse(SubscriptionStatus::Trialing->isTerminal());
    }

    public function test_every_status_has_a_label(): void
    {
        foreach (SubscriptionStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }

    public function test_a_billing_interval_ends_a_period_without_overflowing_the_month(): void
    {
        $this->assertSame('2026-02-28', BillingInterval::Month->periodEnd(CarbonImmutable::parse('2026-01-31'))->toDateString());
        $this->assertSame('2026-11-15', BillingInterval::Month->periodEnd(CarbonImmutable::parse('2026-10-15'))->toDateString());
        $this->assertSame('2027-02-28', BillingInterval::Year->periodEnd(CarbonImmutable::parse('2026-02-28'))->toDateString());
        $this->assertSame('2029-02-28', BillingInterval::Year->periodEnd(CarbonImmutable::parse('2028-02-29'))->toDateString());
    }
}
