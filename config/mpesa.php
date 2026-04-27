<?php

return [

    /*
    |--------------------------------------------------------------------------
    | M-Pesa Daraja Environment
    |--------------------------------------------------------------------------
    |
    | Either "sandbox" or "production". Picks the API base URL.
    |
    */

    'env' => env('MPESA_ENV', 'sandbox'),

    'base_url' => env('MPESA_ENV', 'sandbox') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke',

    /*
    |--------------------------------------------------------------------------
    | Daraja App Credentials
    |--------------------------------------------------------------------------
    |
    | Created in your Daraja portal at developer.safaricom.co.ke. The
    | sandbox values default to Safaricom's public test app -- safe to
    | leave unset in dev. Production values must be set in .env.
    |
    */

    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Lipa Na M-Pesa Online (STK Push)
    |--------------------------------------------------------------------------
    |
    | shortcode = your paybill or till number (174379 = Safaricom sandbox).
    | passkey   = the Lipa Na M-Pesa Online passkey from Daraja.
    | callback_url = a publicly reachable URL that Daraja will POST results
    |                to. For local dev, expose your machine via ngrok and
    |                paste the https URL + /api/mpesa/callback here.
    |
    */

    'shortcode' => env('MPESA_SHORTCODE', '174379'),

    'passkey' => env('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'),

    'callback_url' => env('MPESA_CALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Transaction Type
    |--------------------------------------------------------------------------
    |
    | "CustomerPayBillOnline" for paybill numbers (e.g. 247247).
    | "CustomerBuyGoodsOnline" for till numbers (Buy Goods).
    |
    */

    'transaction_type' => env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Token Cache TTL
    |--------------------------------------------------------------------------
    |
    | Daraja access tokens last 3600 seconds. Cache slightly less to leave
    | a safety margin.
    |
    */

    'token_ttl_seconds' => 3500,

];
