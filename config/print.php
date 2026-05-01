<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Print bridge token
    |--------------------------------------------------------------------------
    |
    | Shared secret between Laravel and the Node print bridge. The bridge
    | sends `X-Bridge-Token: <value>` on every request to /api/print-jobs/*.
    | Generate a long random string and put it in BOTH .env files
    | (Laravel's and the bridge's).
    |
    */

    'bridge_token' => env('PRINT_BRIDGE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Auto-queue receipt on payment
    |--------------------------------------------------------------------------
    |
    | When true, every successful payment (cash, manual mpesa code, or STK
    | callback success) automatically enqueues a print job. The waiter
    | doesn't need to tap "Print receipt" -- the bridge picks it up
    | seconds after payment confirms. Set to false to keep printing
    | manual-only.
    |
    */

    'auto_queue' => filter_var(env('PRINT_AUTO_QUEUE', true), FILTER_VALIDATE_BOOL),

];
