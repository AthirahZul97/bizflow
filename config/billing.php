<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing provider
    |--------------------------------------------------------------------------
    |
    | Phase 2D has no real payment provider. "manual" supports the commercial
    | domain only: paid plans are assigned by an operator with billing:assign.
    |
    */

    'provider' => env('BILLING_PROVIDER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Contact address
    |--------------------------------------------------------------------------
    |
    | Shown as the "Contact us to upgrade" link while paid plans are assigned by hand.
    |
    */

    'contact_email' => env('BILLING_CONTACT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    |
    | Days of full access after a paid period ends without renewal, or after a
    | failed payment. There is no grace after a trial or for the Legacy plan.
    |
    */

    'grace_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Usage calendar
    |--------------------------------------------------------------------------
    |
    | Monthly limits (invoices.monthly_max) count a calendar month in this zone.
    |
    */

    'timezone' => 'Asia/Kuala_Lumpur',

    /*
    |--------------------------------------------------------------------------
    | System plan codes
    |--------------------------------------------------------------------------
    |
    | The only plans application code refers to by name; every other plan is
    | catalogue data.
    |
    */

    'plans' => [
        'legacy' => 'legacy',
        'trial' => 'trial',
        'free' => 'free',
    ],

];
