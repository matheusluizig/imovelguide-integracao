<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Integration System Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações específicas do sistema de integração
    |
    */

    'chunk_size' => env('INTEGRATION_CHUNK_SIZE', 200),
    'max_parallel_chunks_per_integration' => env('INTEGRATION_MAX_PARALLEL_CHUNKS', 3),
    'timeout' => env('INTEGRATION_TIMEOUT', 1800), // 30 minutos
    'max_retries' => env('INTEGRATION_MAX_RETRIES', 3),
    'retry_delays' => [60, 300, 900], // 1min, 5min, 15min

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de cache para o sistema de integração
    |
    */

    'cache' => [
        'default_ttl' => env('INTEGRATION_CACHE_TTL', 3600), // 1 hora
        'xml_ttl' => env('INTEGRATION_XML_CACHE_TTL', 3600), // 1 hora
        'metrics_ttl' => env('INTEGRATION_METRICS_CACHE_TTL', 300), // 5 minutos
        'user_data_ttl' => env('INTEGRATION_USER_CACHE_TTL', 1800), // 30 minutos
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de performance e otimização
    |
    */

    'performance' => [
        'batch_size' => env('INTEGRATION_BATCH_SIZE', 50),
        'memory_limit' => env('INTEGRATION_MEMORY_LIMIT', '512M'),
        'max_execution_time' => env('INTEGRATION_MAX_EXECUTION_TIME', 1800),
        'enable_query_logging' => env('INTEGRATION_QUERY_LOGGING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de monitoramento e métricas
    |
    */

    'monitoring' => [
        'enable_metrics' => env('INTEGRATION_ENABLE_METRICS', true),
        'metrics_retention_days' => env('INTEGRATION_METRICS_RETENTION', 30),
        'alert_thresholds' => [
            'execution_time' => env('INTEGRATION_ALERT_EXECUTION_TIME', 600), // 10 minutos
            'error_rate' => env('INTEGRATION_ALERT_ERROR_RATE', 10), // 10%
            'queue_size' => env('INTEGRATION_ALERT_QUEUE_SIZE', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de processamento de imagens
    |
    */

    'images' => [
        'max_size_mb' => env('INTEGRATION_MAX_IMAGE_SIZE', 5),
        'formats' => ['webp', 'jpg', 'jpeg', 'png'],
        'sizes' => [
            'large' => ['width' => 768, 'height' => 432],
            'medium' => ['width' => 360, 'height' => 280],
            'small' => ['width' => 280, 'height' => 250],
        ],
        'quality' => env('INTEGRATION_IMAGE_QUALITY', 85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações específicas das filas de integração
    |
    */

    'queue' => [
        'name' => env('INTEGRATION_QUEUE_NAME', 'integrations'),
        'connection' => env('INTEGRATION_QUEUE_CONNECTION', 'redis'),
        'max_jobs' => env('INTEGRATION_MAX_JOBS', 1000),
        'timeout' => env('INTEGRATION_QUEUE_TIMEOUT', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações de logging específicas
    |
    */

    'logging' => [
        'level' => env('INTEGRATION_LOG_LEVEL', 'info'),
        'channels' => ['daily', 'slack'],
        'include_context' => env('INTEGRATION_LOG_CONTEXT', true),
        'max_file_size' => env('INTEGRATION_LOG_MAX_SIZE', '100MB'),
    ],
];
