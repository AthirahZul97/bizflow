<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Any authenticated user may list expenses; the query itself is scoped to them.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may record expenses for themselves.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expense $expense): Response
    {
        return $this->owns($user, $expense);
    }

    public function update(User $user, Expense $expense): Response
    {
        return $this->owns($user, $expense);
    }

    public function delete(User $user, Expense $expense): Response
    {
        return $this->owns($user, $expense);
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
