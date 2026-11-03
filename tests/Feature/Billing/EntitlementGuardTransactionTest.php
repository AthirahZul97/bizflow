<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementGuard;
use LogicException;
use Tests\TestCase;

/**
 * Deliberately without RefreshDatabase: that trait wraps every test in a transaction, which
 * would hide the very condition under test (no transaction open).
 */
class EntitlementGuardTransactionTest extends TestCase
{
    public function test_the_business_lock_refuses_to_run_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        EntitlementGuard::lock(1);
    }
}
