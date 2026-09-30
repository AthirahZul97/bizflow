<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    /**
     * Any authenticated user may list items; the query itself is scoped to them.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any authenticated user may create items for themselves.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the item.
     */
    public function view(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    /**
     * Determine whether the user can update the item.
     */
    public function update(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    /**
     * Determine whether the user can delete the item.
     */
    public function delete(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    /**
     * Another business's item is reported as not found, so record IDs cannot be probed.
     */
    private function owns(User $user, Product $product): Response
    {
        return $this->currentBusiness->owns($user, $product)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
