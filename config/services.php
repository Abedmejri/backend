<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama3-8b-8192'), // Default model
        'timeout' => (int) env('GROQ_TIMEOUT', 60), // Default timeout
    ],

    'whisper_api' => [
        'url' => env('WHISPER_API_URL', 'http://localhost:8001'), // Default to localhost:8001
        'timeout' => env('WHISPER_API_TIMEOUT', 180), // Default timeout 3 minutes
    ],
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],
    'twilio' => [
    'sid'   => env('TWILIO_ACCOUNT_SID'),
    'token' => env('TWILIO_AUTH_TOKEN'),
    'key'   => env('TWILIO_API_KEY_SID'),      // Optional: For API Key based auth
    'secret'=> env('TWILIO_API_KEY_SECRET'),  // Optional: For API Key based auth
    'from'  => env('TWILIO_PHONE_NUMBER'),
    'app_sid' => env('TWILIO_TWIML_APP_SID'), 
],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),         
    'client_secret' => env('GOOGLE_CLIENT_SECRET'), 
    'redirect' => env('GOOGLE_REDIRECT_URI'),      
],

];
