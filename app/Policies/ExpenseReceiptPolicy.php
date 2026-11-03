<?php

namespace App\Policies;

use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Policies\Concerns\ChecksSubscription;
use App\Support\CurrentBusiness;
use Illuminate\Auth\Access\Response;

/**
 * Ownership is checked first: another business's receipt is always reported as not found.
 * Only then is the subscription asked, so a 403 never says anything about another business.
 * ExpenseReceiptService re-checks everything authoritatively.
 */
class ExpenseReceiptPolicy
{
    use ChecksSubscription;

    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementService $entitlements,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Uploading a receipt uses one unit of the plan's monthly OCR allowance.
     */
    public function create(User $user): Response
    {
        return $this->subscriptionAllows(Entitlement::ReceiptOcr);
    }

    public function view(User $user, ExpenseReceipt $expenseReceipt): Response
    {
        return $this->owns($user, $expenseReceipt);
    }

    /**
     * Confirming and switching to manual entry: needs write access only. The extraction was
     * already paid for, so confirming never uses more OCR allowance.
     */
    public function update(User $user, ExpenseReceipt $expenseReceipt): Response
    {
        return $this->ownsForWrite($user, $expenseReceipt);
    }

    /**
     * Running the OCR again: the plan must still include it. Whether the allowance has room is
     * decided by the service, under the business lock.
     */
    public function retry(User $user, ExpenseReceipt $expenseReceipt): Response
    {
        $ownership = $this->owns($user, $expenseReceipt);

        return $ownership->denied() ? $ownership : $this->planIncludesOcr();
    }

    public function delete(User $user, ExpenseReceipt $expenseReceipt): Response
    {
        return $this->ownsForWrite($user, $expenseReceipt);
    }

    private function ownsForWrite(User $user, ExpenseReceipt $receipt): Response
    {
        $ownership = $this->owns($user, $receipt);

        return $ownership->denied() ? $ownership : $this->subscriptionAllowsWrites();
    }

    private function planIncludesOcr(): Response
    {
        $check = $this->entitlements->for($this->currentBusiness->get())->check(Entitlement::ReceiptOcr, 0);

        return $check->allowed ? Response::allow() : Response::deny($check->message());
    }

    private function owns(User $user, ExpenseReceipt $receipt): Response
    {
        return $this->currentBusiness->owns($user, $receipt)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
