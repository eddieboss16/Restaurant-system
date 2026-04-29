<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta WhatsApp Cloud API
    |--------------------------------------------------------------------------
    |
    | Setup at developers.facebook.com:
    | 1. Create a Meta app, add "WhatsApp" product.
    | 2. Get the test phone number id (or your own approved one).
    | 3. Generate a permanent access token (System User token, not the
    |    24h temporary one shown by default).
    | 4. Add the owner's phone number to the test recipient list (sandbox)
    |    or use any phone (production with approved templates).
    | 5. For production, get a message template approved -- text messages
    |    only work inside a 24h window after the recipient messages you.
    |
    */

    'enabled' => filter_var(env('WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOL),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v20.0'),

    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    /**
     * The owner's phone number to send daily summaries to. Format: 254712345678.
     */
    'owner_recipient' => env('WHATSAPP_OWNER_PHONE'),

    /**
     * Optional pre-approved template name for the daily summary. If set,
     * sendDailySummary uses a template message; if null, sends as a plain
     * text message (which only works inside the 24h conversation window
     * or with whitelisted sandbox recipients).
     */
    'daily_summary_template' => env('WHATSAPP_DAILY_SUMMARY_TEMPLATE'),

    'daily_summary_template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),

];
