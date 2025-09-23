<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [
  /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

  'default' => env('LOG_CHANNEL', 'stack'),

  /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

  'deprecations' => [
    'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
    'trace' => false,
  ],

  /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

  'channels' => [
    'stack' => [
      'driver' => 'stack',
      'channels' => ['single', 'discord'],
      'ignore_exceptions' => false,
    ],

    'single' => [
      'driver' => 'single',
      'path' => storage_path('logs/laravel.log'),
      'level' => env('LOG_LEVEL', 'debug'),
    ],

    'daily' => [
      'driver' => 'daily',
      'path' => storage_path('logs/laravel.log'),
      'level' => env('LOG_LEVEL', 'debug'),
      'days' => 14,
      'permission' => 0664,
    ],

    'slack' => [
      'driver' => 'slack',
      'url' => env('LOG_SLACK_WEBHOOK_URL'),
      'username' => 'Laravel Log',
      'emoji' => ':boom:',
      'level' => env('LOG_LEVEL', 'critical'),
    ],

    'papertrail' => [
      'driver' => 'monolog',
      'level' => env('LOG_LEVEL', 'debug'),
      'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
      'handler_with' => [
        'host' => env('PAPERTRAIL_URL'),
        'port' => env('PAPERTRAIL_PORT'),
        'connectionString' => 'tls://' . env('PAPERTRAIL_URL') . ':' . env('PAPERTRAIL_PORT'),
      ],
    ],

    'stderr' => [
      'driver' => 'monolog',
      'level' => env('LOG_LEVEL', 'debug'),
      'handler' => StreamHandler::class,
      'formatter' => env('LOG_STDERR_FORMATTER'),
      'with' => [
        'stream' => 'php://stderr',
      ],
    ],

    'integration' => [
      'driver' => 'daily',
      'path' => storage_path('logs/integration.log'),
      'level' => env('INTEGRATION_LOG_LEVEL', 'info'),
      'days' => env('INTEGRATION_LOG_RETENTION_DAYS', 30),
      'permission' => 0664,
    ],

    'integration_errors' => [
      'driver' => 'daily',
      'path' => storage_path('logs/integration-errors.log'),
      'level' => 'error',
      'days' => env('INTEGRATION_ERROR_LOG_RETENTION_DAYS', 90),
      'permission' => 0664,
    ],

    'integration_performance' => [
      'driver' => 'daily',
      'path' => storage_path('logs/integration-performance.log'),
      'level' => 'info',
      'days' => env('INTEGRATION_PERFORMANCE_LOG_RETENTION_DAYS', 7),
      'permission' => 0664,
    ],

    'syslog' => [
      'driver' => 'syslog',
      'level' => env('LOG_LEVEL', 'debug'),
    ],

    'errorlog' => [
      'driver' => 'errorlog',
      'level' => env('LOG_LEVEL', 'debug'),
    ],

    'null' => [
      'driver' => 'monolog',
      'handler' => NullHandler::class,
    ],

    'emergency' => [
      'path' => storage_path('logs/laravel.log'),
    ],

    'integrationErroEAvisos' => [
      'driver' => 'daily',
      'path' => storage_path('logs/errosEavisosDeIntegracoes.log'),
      'level' => 'debug',
      'days' => 14,
    ],
    'integracoesFeitas' => [
      'driver' => 'daily',
      'path' => storage_path('logs/integracoesFeitas.log'),
      'level' => 'debug',
      'days' => 14,
    ],
    'siteCorretores' => [
      'driver' => 'daily',
      'path' => storage_path('logs/siteCorretores.log'),
      'level' => 'debug',
      'days' => 14,
    ],
    'planosAtualizacoes' => [
      'driver' => 'daily',
      'path' => storage_path('logs/planosAtualizacoes.log'),
      'level' => 'debug',
      'days' => 14,
    ],
    'websiteDominios' => [
      'driver' => 'daily',
      'path' => storage_path('logs/websiteDominios.log'),
      'level' => 'debug',
      'days' => 14,
    ],
    'cronJobs' => [
      'driver' => 'daily',
      'path' => storage_path('logs/cronJobs.log'),
      'level' => 'debug',
      'days' => 14,
    ],
    'discord' => [
      'driver' => 'monolog',
      'handler' => App\Logging\DiscordHandler::class,
      'level' => 'critical',
      'with' => [
        'webhookUrl' => env('DISCORD_WEBHOOK_URL'),
      ],
    ],
    'discord_integration' => [
      'driver' => 'monolog',
      'handler' => App\Logging\DiscordHandler::class,
      'level' => 'info',
      'with' => [
        'webhookUrl' => env('DISCORD_INTEGRATION_WEBHOOK_URL'),
      ],
    ],

    'discord_atualizacoes_planos_erros' => [
      'driver' => 'monolog',
      'handler' => App\Logging\DiscordHandler::class,
      'level' => 'warning',
      'with' => [
        'webhookUrl' => env('DISCORD_ATUALIZACOES_PLANOS_ERROS'),
      ],
    ],

    'error500' => [
      'driver' => 'daily',
      'path' => storage_path('logs/error500.log'),
      'level' => 'error',
      'days' => 14,
    ],
  ],
];