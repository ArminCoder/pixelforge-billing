<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    // Use one key for local and another for the deployed app.
    'api_key' => env('PIXELFORGE_API_KEY'),

    // The "whsec_..." secret of the same key, used to check webhook signatures.
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET', ''),

    // The user pays Pixelforge's cost plus this percentage.
    'markup_percent' => 25,

    // What the fake "Buy credits" button adds, in nanodollars (5 USD).
    'credit_pack_nanos' => 5_000_000_000,

    // Comma separated emails that may open the admin report.
    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', '')))),

];
