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

];
