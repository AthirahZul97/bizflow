<?php

namespace App\Enums;

use App\Billing\Meters\CustomerCountMeter;
use App\Billing\Meters\MonthlyIssuedInvoicesMeter;
use App\Billing\Meters\MonthlyReceiptOcrMeter;
use App\Billing\Meters\ProductCountMeter;
use App\Billing\Meters\RecurringScheduleMeter;
use App\Billing\Meters\TeamSeatMeter;
use App\Billing\UsageMeter;

/**
 * The catalogue of things a plan can grant. A plan's entitlements JSON is keyed by these
 * values: an integer is a limit, null is unlimited, 0 is "not included", a boolean is a flag,
 * and an absent key is denied. A new entitlement is one case here (plus a meter if it is a
 * limit), enforced at its write path, and a value in a new plan version.
 */
enum Entitlement: string
{
    case Customers = 'customers.max';
    case Products = 'products.max';
    case InvoicesPerMonth = 'invoices.monthly_max';
    case RecurringInvoices = 'recurring_invoices.max';
    case TeamSeats = 'team.seats';
    case InvoiceEmail = 'invoices.email';
    case ReceiptOcr = 'expenses.ocr_monthly_max';

    /**
     * Get the human-readable label for the entitlement.
     */
    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Customers',
            self::Products => 'Products & services',
            self::InvoicesPerMonth => 'Invoices issued per month',
            self::RecurringInvoices => 'Recurring invoices',
            self::TeamSeats => 'Team members',
            self::InvoiceEmail => 'Email invoices',
            self::ReceiptOcr => 'Receipt scans per month',
        };
    }

    /**
     * What the entitlement is called in a sentence about its limit ("customers").
     */
    public function noun(): string
    {
        return match ($this) {
            self::Customers => 'customers',
            self::Products => 'products and services',
            self::InvoicesPerMonth => 'invoices a month',
            self::RecurringInvoices => 'recurring invoices',
            self::TeamSeats => 'team members',
            self::InvoiceEmail => 'invoice emails',
            self::ReceiptOcr => 'receipt scans a month',
        };
    }

    public function isLimit(): bool
    {
        return $this !== self::InvoiceEmail;
    }

    public function isFlag(): bool
    {
        return ! $this->isLimit();
    }

    /**
     * The value used when a plan does not mention the entitlement: denied.
     */
    public function default(): int|bool
    {
        return $this->isLimit() ? 0 : false;
    }

    /**
     * Counts what a business currently uses; null for flags.
     */
    public function meter(): ?UsageMeter
    {
        return match ($this) {
            self::Customers => new CustomerCountMeter,
            self::Products => new ProductCountMeter,
            self::InvoicesPerMonth => new MonthlyIssuedInvoicesMeter,
            self::RecurringInvoices => new RecurringScheduleMeter,
            self::TeamSeats => new TeamSeatMeter,
            self::ReceiptOcr => new MonthlyReceiptOcrMeter,
            self::InvoiceEmail => null,
        };
    }
}
