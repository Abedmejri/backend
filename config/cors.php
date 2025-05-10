<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines which cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

  // config/cors.php
'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout','/forgot-password','/reset-password','/generate-resume'], // Make sure 'sanctum/csrf-cookie' is included if not covered by api/*
'allowed_methods' => ['*'],
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')], // Use environment variable
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'], // Or be more specific: ['Content-Type', 'X-Requested-With', 'Authorization', 'X-XSRF-TOKEN']
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true, // MUST be true for SPA auth

];