<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // vihara-finance-web dev
        'http://localhost:3500',
        'http://127.0.0.1:3500',
        // Production client host
        'https://vihara-finance.kalyanamittanusantara.com',
    ],

    // Also allow any *.kalyanamittanusantara.com subdomain (mobile / POS /
    // yayasan-console may live under other prefixes as the roll-out expands).
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.kalyanamittanusantara\.com$#i',
    ],

    'allowed_headers' => ['*'],

    // Nama berkas unduhan dibaca peramban dari header ini. Tanpa
    // diekspos, permintaan lintas asal (web 3500 → API 8500) tidak bisa
    // membacanya dan unduhan jatuh ke nama bawaan (FV-59).
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,
];
