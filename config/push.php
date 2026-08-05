<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    |
    | Keys identify this deployment to the browsers' push services. They cost
    | nothing and are generated once with:
    |
    |   php artisan gallery:push-keys
    |
    | Then add the three values to .env. Regenerating them invalidates every
    | existing subscription, so do it only deliberately.
    |
    | With no keys configured, push delivery is skipped and reminders still
    | arrive in the application itself — nothing breaks, it just stays quiet
    | while the app is closed.
    |
    */

    'subject'     => env('VAPID_SUBJECT'),
    'public_key'  => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),

    // How long a push service may hold an undelivered message, in seconds.
    'ttl' => (int) env('PUSH_TTL', 3600),

];
