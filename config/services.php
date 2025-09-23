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

  'mailgun' => [
    'domain' => env('MAILGUN_DOMAIN'),
    'secret' => env('MAILGUN_SECRET'),
    'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    'scheme' => 'https',
  ],

  'postmark' => [
    'token' => env('POSTMARK_TOKEN'),
  ],

  'ses' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
  ],

  'asaas' => [
    'legacy' => [
      'sandbox' => env('ASAAS_LEGACY_SANDBOX'),
      'api' => env('ASAAS_LEGACY_API'),
      'token' => env('ASAAS_LEGACY_API'),
      'api_url' => env('ASAAS_LEGACY_API_URL', 'https://api.asaas.com/v3'),
      'checkout_url' => env('ASAAS_LEGACY_CHECKOUT_URL', 'https://asaas.com/checkout'),
    ],
    'new' => [
      'sandbox' => env('ASAAS_NEW_SANDBOX'),
      'api' => env('ASAAS_NEW_API'),
      'token' => env('ASAAS_NEW_API'),
      'api_url' => env('ASAAS_NEW_API_URL', 'https://api.asaas.com/v3'),
      'checkout_url' => env('ASAAS_NEW_CHECKOUT_URL', 'https://asaas.com/checkout'),
    ],
    'sandbox' => env('ASAAS_SANDBOX'),
    'api' => env('ASAAS_API'),
    'token' => env('ASAAS_API'),
    'api_url' => env('ASAAS_API_URL', 'https://api.asaas.com/v3'),
    'checkout_url' => env('ASAAS_CHECKOUT_URL', 'https://asaas.com/checkout'),
  ],

  'sendgrid' => [
    'email_validation_api' => env('SENDGRID_EMAIL_VALIDATION_API'),
  ],
];