<?php

return [
    // VAPID keys identify this server to the browser push services. Generated
    // once with `php artisan webpush:vapid` and stored in .env.
    'vapid' => [
        'subject' => env('WEBPUSH_SUBJECT', 'mailto:imprint.customs@gmail.com'),
        'public_key' => env('WEBPUSH_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_PRIVATE_KEY'),
    ],
];
