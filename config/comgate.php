<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Comgate payment gateway
    |--------------------------------------------------------------------------
    |
    | Credentials come from the environment and are never committed. Obtain them
    | from the Comgate merchant portal; `test => true` routes everything to the
    | sandbox so no real money moves.
    |
    | Required in .env before payments can run:
    |   COMGATE_MERCHANT=...
    |   COMGATE_SECRET=...
    |   COMGATE_TEST=true
    |
    */

    'merchant' => env('COMGATE_MERCHANT'),
    'secret'   => env('COMGATE_SECRET'),
    'test'     => (bool) env('COMGATE_TEST', true),

    'base_url' => env('COMGATE_BASE_URL', 'https://payments.comgate.cz/v1.0'),

    'country'  => env('COMGATE_COUNTRY', 'CZ'),
    'currency' => env('COMGATE_CURRENCY', 'CZK'),

    // 'ALL' lets the payer choose on Comgate's own page, which is the least
    // brittle option and keeps card/bank/wallet support up to them.
    'method'   => env('COMGATE_METHOD', 'ALL'),

    /*
    | The gateway calls `notify_url` server-to-server; that callback is what
    | actually marks a payment paid. The browser return URLs are cosmetic and
    | must never be trusted as proof of payment.
    */
    'notify_path'  => 'platby/comgate/notifikace',
    'return_path'  => 'platby/comgate/navrat',

];
