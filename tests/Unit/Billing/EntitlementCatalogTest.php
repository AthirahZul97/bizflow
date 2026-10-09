<?php

namespace Tests\Unit\Billing;

use App\Billing\UsageMeter;
use App\Enums\Entitlement;
use PHPUnit\Framework\TestCase;

class EntitlementCatalogTest extends TestCase
{
    public function test_the_initial_catalogue_is_exactly_the_approved_entitlements(): void
    {
        $this->assertSame([
            'customers.max',
            'products.max',
            'invoices.monthly_max',
            'recurring_invoices.max',
            'team.seats',
            'invoices.email',
            // Phase 2E: the monthly receipt OCR allowance.
            'expenses.ocr_monthly_max',
        ], array_map(fn (Entitlement $e) => $e->value, Entitlement::cases()));
    }

    public function test_every_entitlement_is_either_a_limit_or_a_flag(): void
    {
        foreach (Entitlement::cases() as $entitlement) {
            $this->assertNotSame($entitlement->isLimit(), $entitlement->isFlag(), $entitlement->value);
        }

        $this->assertTrue(Entitlement::InvoiceEmail->isFlag());
        $this->assertTrue(Entitlement::Customers->isLimit());
    }

    public function test_an_absent_entitlement_defaults_to_denied(): void
    {
        foreach (Entitlement::cases() as $entitlement) {
            $this->assertSame($entitlement->isLimit() ? 0 : false, $entitlement->default(), $entitlement->value);
        }
    }

    public function test_every_limit_has_a_usage_meter_and_no_flag_does(): void
    {
        foreach (Entitlement::cases() as $entitlement) {
            if ($entitlement->isLimit()) {
                $this->assertInstanceOf(UsageMeter::class, $entitlement->meter(), $entitlement->value);
            } else {
                $this->assertNull($entitlement->meter(), $entitlement->value);
            }
        }
    }

    public function test_every_entitlement_has_a_label_and_a_noun(): void
    {
        foreach (Entitlement::cases() as $entitlement) {
            $this->assertNotSame('', $entitlement->label());
            $this->assertNotSame('', $entitlement->noun());
        }
    }
}
