<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
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
     * Another user's expense is reported as not found, so record IDs cannot be probed.
     */
    private function owns(User $user, Expense $expense): Response
    {
        return $expense->user()->is($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
