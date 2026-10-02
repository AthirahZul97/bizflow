<?php

namespace Tests\Unit\Billing;

use App\Enums\Entitlement;
use App\Models\Plan;
use PHPUnit\Framework\TestCase;

class PlanEntitlementValueTest extends TestCase
{
    private function plan(array $entitlements): Plan
    {
        return (new Plan)->forceFill(['entitlements' => $entitlements]);
    }

    public function test_an_integer_is_a_limit(): void
    {
        $this->assertSame(25, $this->plan(['customers.max' => 25])->valueOf(Entitlement::Customers));
    }

    public function test_null_is_unlimited(): void
    {
        $this->assertNull($this->plan(['customers.max' => null])->valueOf(Entitlement::Customers));
    }

    public function test_zero_is_not_included(): void
    {
        $this->assertSame(0, $this->plan(['recurring_invoices.max' => 0])->valueOf(Entitlement::RecurringInvoices));
    }

    public function test_a_flag_is_true_only_when_exactly_true(): void
    {
        $this->assertTrue($this->plan(['invoices.email' => true])->valueOf(Entitlement::InvoiceEmail));
        $this->assertFalse($this->plan(['invoices.email' => false])->valueOf(Entitlement::InvoiceEmail));
        $this->assertFalse($this->plan(['invoices.email' => 1])->valueOf(Entitlement::InvoiceEmail));
        $this->assertFalse($this->plan(['invoices.email' => 'yes'])->valueOf(Entitlement::InvoiceEmail));
    }

    public function test_an_absent_entitlement_is_denied_never_allowed(): void
    {
        $plan = $this->plan([]);

        $this->assertSame(0, $plan->valueOf(Entitlement::Customers));
        $this->assertFalse($plan->valueOf(Entitlement::InvoiceEmail));
    }

    public function test_a_plan_with_no_entitlements_at_all_denies_everything(): void
    {
        $plan = (new Plan);

        foreach (Entitlement::cases() as $entitlement) {
            $this->assertSame($entitlement->default(), $plan->valueOf($entitlement), $entitlement->value);
        }
    }

    public function test_a_malformed_limit_is_denied_not_unlimited(): void
    {
        $this->assertSame(0, $this->plan(['customers.max' => 'lots'])->valueOf(Entitlement::Customers));
        $this->assertSame(0, $this->plan(['customers.max' => -5])->valueOf(Entitlement::Customers));
        $this->assertSame(0, $this->plan(['customers.max' => 1.5])->valueOf(Entitlement::Customers));
        $this->assertSame(0, $this->plan(['customers.max' => true])->valueOf(Entitlement::Customers));
    }

    public function test_unknown_keys_in_the_plan_are_ignored(): void
    {
        $plan = $this->plan(['future.thing' => 5, 'customers.max' => 3]);

        $this->assertSame(3, $plan->valueOf(Entitlement::Customers));
        $this->assertSame(0, $plan->valueOf(Entitlement::Products));
    }

    public function test_free_and_paid(): void
    {
        $this->assertTrue((new Plan)->forceFill(['price' => '0.00'])->isFree());
        $this->assertTrue((new Plan)->forceFill(['price' => '0'])->isFree());
        $this->assertTrue((new Plan)->forceFill(['price' => '9.90'])->isPaid());
    }
}
