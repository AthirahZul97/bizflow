<?php

namespace Tests\Unit;

use App\Enums\InvoiceEmailStatus;
use PHPUnit\Framework\TestCase;

class InvoiceEmailStatusTest extends TestCase
{
    public function test_only_queued_and_sending_are_active(): void
    {
        $active = array_values(array_filter(InvoiceEmailStatus::cases(), fn (InvoiceEmailStatus $s) => $s->isActive()));

        $this->assertSame([InvoiceEmailStatus::Queued, InvoiceEmailStatus::Sending], $active);
        foreach ([InvoiceEmailStatus::Sent, InvoiceEmailStatus::Failed, InvoiceEmailStatus::Skipped] as $status) {
            $this->assertTrue($status->isFinal());
        }
    }

    public function test_every_status_has_a_label_and_badge(): void
    {
        foreach (InvoiceEmailStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertStringStartsWith('text-bg-', $status->badgeClass());
        }
        $this->assertSame('Not sent', InvoiceEmailStatus::Skipped->label());
    }
}
