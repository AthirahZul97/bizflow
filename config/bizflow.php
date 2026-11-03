<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | BizFlow is single-currency for the MVP. Amounts are stored without a
    | currency; this setting only controls how they are displayed.
    |
    */

    'currency' => [
        'code' => 'MYR',
        'symbol' => 'RM',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoices
    |--------------------------------------------------------------------------
    |
    | Invoice numbers are assigned per business when an invoice is issued, e.g.
    | INV-00001. The sequence is continuous and never reset or reused.
    |
    */

    'invoice' => [
        'number_prefix' => 'INV-',
        'number_padding' => 5,
        'payment_terms_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment profile and demo mode
    |--------------------------------------------------------------------------
    |
    | Demo mode makes the application more restrictive, never less: registration is closed, no
    | invoice email is queued, claimed or delivered, and the email-based account flows (password
    | reset, email verification) are switched off. It is decided here, from the environment, and
    | never from anything in a request. Nothing in this file grants an entitlement.
    |
    | "registration" is "open" or "closed"; demo mode forces it closed (see App\Support\DemoMode).
    |
    */

    'demo' => (bool) env('BIZFLOW_DEMO_MODE', false),

    'registration' => env('BIZFLOW_REGISTRATION', 'open'),

    /*
    | When on, an unverified account cannot send invoice emails. Off by default so local
    | development is unchanged; switch it on for a private beta that has a real mailer.
    */
    'require_verified_email' => (bool) env('BIZFLOW_REQUIRE_VERIFIED_EMAIL', false),

    /*
    |--------------------------------------------------------------------------
    | Mail transports
    |--------------------------------------------------------------------------
    |
    | Which MAIL_MAILER values are acceptable per deployment profile. Demo never delivers email, so
    | only the transports that keep a message inside the application are allowed. "bizflow:preflight"
    | enforces this; the delivery guard in App\Support\DemoMode backs it up at send time.
    |
    */

    'mail' => [
        'demo_transports' => ['log', 'array'],
        'production_transports' => ['smtp', 'ses', 'postmark', 'resend'],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP security
    |--------------------------------------------------------------------------
    |
    | "hsts_max_age" is sent only on a request that is verifiably HTTPS (after trusted proxies are
    | applied); 0 turns it off. The CSP is report-only until it has been checked in real browsers.
    | Trusted proxies live in config/trustedproxy.php.
    |
    */

    'security' => [
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 0),

        // The cache store that counts /health requests. Not the database: the probe must work when it is down.
        'health_limiter_store' => env('HEALTH_LIMITER_STORE', 'file'),
    ],

];
