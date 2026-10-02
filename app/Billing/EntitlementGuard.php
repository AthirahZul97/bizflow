<?php

namespace App\Billing;

use App\Enums\Entitlement;
use App\Exceptions\EntitlementException;
use App\Models\Business;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates a record only if the business's plan allows one more, atomically, for records
 * (customers, products) that have no service of their own.
 *
 * The check and the insert happen under the business row lock, so two requests at the limit
 * can't both pass: the second waits for the lock, then counts the first one's record.
 */
class EntitlementGuard
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $create  Runs only when allowed, inside the transaction.
     * @return T
     *
     * @throws EntitlementException
     */
    public function create(Business $business, Entitlement $entitlement, Closure $create): mixed
    {
        if (! $entitlement->isLimit()) {
            throw new LogicException("{$entitlement->value} is not a limit.");
        }

        return DB::transaction(function () use ($business, $entitlement, $create) {
            self::lock($business);

            // After the lock, and never from the memo: anything earlier may be stale.
            $check = $this->entitlements->fresh($business)->check($entitlement);

            if (! $check->allowed) {
                throw EntitlementException::denied($check);
            }

            return $create();
        });
    }

    /**
     * Take the business row lock. Must be inside a transaction.
     */
    public static function lock(Business|int $business): Business
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The business row can only be locked inside a database transaction.');
        }

        return Business::query()->whereKey($business instanceof Business ? $business->getKey() : $business)->lockForUpdate()->firstOrFail();
    }
}
