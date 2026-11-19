<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Huong Chat API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for connecting to the Huong Chat order service.
    |
    */

    'api_url' => env('HUONG_CHAT_API_URL', 'http://127.0.0.1:8001/api'),
    'api_key' => env('HUONG_CHAT_API_KEY', 'huongchat_secret_key_2026'),
    'timeout' => (int) env('HUONG_CHAT_API_TIMEOUT', 10),
];
