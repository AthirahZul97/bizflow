<?php

namespace App\Http\Middleware;

use App\Billing\EntitlementService;
use App\Exceptions\EntitlementException;
use App\Support\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed read-only enforcement for the business routes.
 *
 * Reading (GET, HEAD, OPTIONS) is always allowed. Any other method is refused with a 403
 * while the business's access is read-only, unless the route is one of the explicitly
 * allow-listed billing actions. Because it is deny-by-default on the method, a write route
 * added later is protected without anyone remembering to list it.
 *
 * It runs before route-model binding (see bootstrap/app.php) so the answer never depends on
 * whether a record ID exists: a forged ID and a missing one are refused identically and
 * nothing about another business leaks.
 *
 * This is the blunt backstop. Policies give the precise messages and the services and
 * EntitlementGuard enforce limits; none of them rely on this alone.
 */
class EnsureSubscriptionWritable
{
    /**
     * The only writes a read-only business may make: choosing a plan and managing its
     * subscription. Each is further restricted to the owner by BusinessPolicy::manageBilling.
     *
     * @var list<string>
     */
    public const READ_ONLY_ALLOWED_ROUTES = [
        'billing.change',
        'billing.cancel',
        'billing.resume',
    ];

    public function __construct(
        private readonly CurrentBusiness $currentBusiness,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if ($this->entitlements->for($this->currentBusiness->get())->canWrite()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::READ_ONLY_ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        abort(403, EntitlementException::readOnly()->getMessage());
    }
}
