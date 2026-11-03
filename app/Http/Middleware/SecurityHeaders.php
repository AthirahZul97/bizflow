<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser-side protections for every response, error pages included (it is global
 * middleware, so a 404 or an expired-session 419 gets them too).
 *
 * A header the response already carries is left alone: the receipt file route sets its own,
 * stricter Content-Security-Policy and Referrer-Policy, and those must survive.
 *
 * The CSP is report-only: browsers log what it would have blocked and block nothing. It is not
 * enforced until it has been checked in real browsers. Inline styles are allowed (the views use
 * style attributes); scripts must come from this origin, and no view has an inline script or an
 * event-handler attribute (a test keeps it that way; see resources/js/page-behaviours.js). If an
 * inline script is ever needed, add a per-request nonce then, not an 'unsafe-inline'.
 * frame-ancestors is not valid in a report-only policy, so framing is refused with
 * X-Frame-Options instead.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ];

        // Only on a connection that is verifiably HTTPS: after trusted proxies are applied,
        // isSecure() is true for a forwarded https request from a trusted proxy and for nothing else.
        $hsts = (int) config('bizflow.security.hsts_max_age');

        if ($hsts > 0 && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = "max-age={$hsts}";
        }

        if (DemoMode::enabled()) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
