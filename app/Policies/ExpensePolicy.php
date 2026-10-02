<?php

namespace App\Policies;

use App\Billing\EntitlementService;
use App\Models\Expense;
use App\Models\User;
use App\Policies\Concerns\ChecksSubscription;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    use ChecksSubscription;

    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * Any authenticated user may list expenses; the query itself is scoped to them.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may record expenses for themselves, while the subscription
     * allows writes. Expenses have no plan limit.
     */
    public function create(User $user): Response
    {
        return $this->subscriptionAllowsWrites();
    }

    public function view(User $user, Expense $expense): Response
    {
        return $this->owns($user, $expense);
    }

    public function update(User $user, Expense $expense): Response
    {
        return $this->ownsForWrite($user, $expense);
    }

    public function delete(User $user, Expense $expense): Response
    {
        return $this->ownsForWrite($user, $expense);
    }

    /**
     * Ownership first (404 for another business), then the subscription must allow writes (403).
     */
    private function ownsForWrite(User $user, Expense $expense): Response
    {
        $ownership = $this->owns($user, $expense);

        return $ownership->denied() ? $ownership : $this->subscriptionAllowsWrites();
    }

    /**
     * Another business's expense is reported as not found, so record IDs cannot be probed.
     */
    private function owns(User $user, Expense $expense): Response
    {
        return $this->currentBusiness->owns($user, $expense)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
