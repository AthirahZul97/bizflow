<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The business the authenticated user is working in: the tenant for this request.
 *
 * Only the HTTP layer (controllers, form requests, policies) uses this. Services,
 * jobs and commands receive a Business explicitly. Nothing filters queries
 * automatically; callers query business data from the returned Business.
 *
 * For now every user belongs to exactly one business. A future business switcher
 * changes only how get() picks among the user's memberships.
 */
class CurrentBusiness
{
    private ?Business $business = null;

    private int|string|null $userId = null;

    private ?Request $request = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Container $container,
    ) {}

    /**
     * Get the current business, resolving it from the user's membership.
     *
     * Cached for the current request and user only, so a later request (or a
     * different user, as in tests) always resolves the membership afresh.
     */
    public function get(): Business
    {
        $user = $this->auth->guard()->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        if ($this->business !== null && $this->userId === $user->getKey() && $this->request === $request) {
            return $this->business;
        }

        $businesses = $user->businesses()->orderBy('businesses.id')->limit(2)->get();

        if ($businesses->isEmpty()) {
            // Registration and the tenancy migration always create one, so this is a broken account.
            Log::warning('Authenticated user has no business membership.', ['user_id' => $user->getKey()]);

            throw new AccessDeniedHttpException('Your account isn’t linked to a business.');
        }

        if ($businesses->count() > 1) {
            throw new LogicException('Users with more than one business are not supported yet.');
        }

        $this->userId = $user->getKey();
        $this->request = $request;

        return $this->business = $businesses->first();
    }

    /**
     * The policy rule: the record belongs to the current business, and $user is the
     * member that business was resolved for (so a membership is always behind access).
     */
    public function owns(User $user, Customer|Product|Invoice|Expense $record): bool
    {
        $business = $this->get();

        return $user->getKey() === $this->userId && $record->business()->is($business);
    }
}
