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

];
