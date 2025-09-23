<?php

return [
    'client' => env('REDIS_CLIENT', 'predis'),

    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', 'imovelguide_'),
        'read_timeout' => 300, // 5 minutos para operações longas
        'connect_timeout' => 30, // 30 segundos para conexão
        'retry_interval' => 1000, // 1 segundo entre tentativas
        'max_retries' => 5, // Mais tentativas
        'persistent' => true, // Conexão persistente
        'tcp_keepalive' => 1, // Manter conexão viva
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
        'read_timeout' => 300,
        'connect_timeout' => 30,
        'retry_interval' => 1000,
        'max_retries' => 5,
        'persistent' => true,
        'tcp_keepalive' => 1,
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
        'read_timeout' => 60,
        'connect_timeout' => 10,
        'retry_interval' => 100,
        'max_retries' => 3,
    ],

    'session' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_SESSION_DB', '2'),
        'read_timeout' => 60,
        'connect_timeout' => 10,
        'retry_interval' => 100,
        'max_retries' => 3,
    ],

    'queue' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_QUEUE_DB', '3'),
        'read_timeout' => 300,
        'connect_timeout' => 30,
        'retry_interval' => 1000,
        'max_retries' => 5,
        'persistent' => true,
        'tcp_keepalive' => 1,
    ],

    // Configurações de monitoramento
    'monitoring' => [
        'enabled' => env('REDIS_MONITORING_ENABLED', true),
        'memory_limit' => env('REDIS_MEMORY_LIMIT', '10G'), //memória do redis
        'alert_threshold' => env('REDIS_ALERT_THRESHOLD', 80), // porcentagem
        'check_interval' => env('REDIS_CHECK_INTERVAL', 300), // segundos
    ],

    // Configurações de fallback
    'fallback' => [
        'enabled' => env('REDIS_FALLBACK_ENABLED', true),
        'store' => env('REDIS_FALLBACK_STORE', 'file'),
        'ttl' => env('REDIS_FALLBACK_TTL', 3600), // segundos
    ],
];