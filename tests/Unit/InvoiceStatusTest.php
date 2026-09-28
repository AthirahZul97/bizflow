<?php

namespace Tests\Unit;

use App\Enums\InvoiceStatus;
use PHPUnit\Framework\TestCase;

class InvoiceStatusTest extends TestCase
{
    public function test_only_the_approved_transitions_are_allowed(): void
    {
        $allowed = [
            'draft>issued',
            'issued>paid',
            'issued>cancelled',
            'paid>issued',
        ];

        foreach (InvoiceStatus::cases() as $from) {
            foreach (InvoiceStatus::cases() as $to) {
                $key = "{$from->value}>{$to->value}";

                $this->assertSame(
                    in_array($key, $allowed, true),
                    $from->canTransitionTo($to),
                    "Unexpected rule for {$key}",
                );
            }
        }
    }

    public function test_overdue_is_not_a_stored_status(): void
    {
        $this->assertNull(InvoiceStatus::tryFrom('overdue'));
        $this->assertSame(['draft', 'issued', 'paid', 'cancelled'], array_column(InvoiceStatus::cases(), 'value'));
    }

    public function test_state_helpers(): void
    {
        $this->assertTrue(InvoiceStatus::Draft->isDraft());
        $this->assertTrue(InvoiceStatus::Issued->isIssued());
        $this->assertTrue(InvoiceStatus::Paid->isPaid());
        $this->assertTrue(InvoiceStatus::Cancelled->isCancelled());
        $this->assertFalse(InvoiceStatus::Paid->isIssued());
    }
}
