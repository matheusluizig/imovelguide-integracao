<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;

class IntegrationCacheService
{
    private const CACHE_PREFIX = 'integration_';
    private const DEFAULT_TTL = 3600; 
    private const LONG_TTL = 86400; 
    private const SHORT_TTL = 300; 

    public function cacheIntegrationData(Integracao $integration): void
    {
        $cacheKey = $this->getIntegrationCacheKey($integration->id);

        $data = [
            'id' => $integration->id,
            'user_id' => $integration->user_id,
            'status' => $integration->status,
            'system' => $integration->system,
            'link' => $integration->link,
            'created_at' => $integration->created_at,
            'updated_at' => $integration->updated_at,
            'user' => [
                'id' => $integration->user->id,
                'name' => $integration->user->name,
                'inative' => $integration->user->inative,
                'level' => $integration->user->level ?? 0
            ]
        ];

        Cache::put($cacheKey, $data, self::DEFAULT_TTL);

        Log::debug("Integration data cached", [
            'integration_id' => $integration->id,
            'cache_key' => $cacheKey
        ]);
    }

    public function getCachedIntegration(int $integrationId): ?array
    {
        $cacheKey = $this->getIntegrationCacheKey($integrationId);
        $data = Cache::get($cacheKey);

        if ($data) {
            Log::debug("Integration data loaded from cache", [
                'integration_id' => $integrationId,
                'cache_key' => $cacheKey
            ]);
        }

        return $data;
    }

    public function cacheQueueStatus(IntegrationsQueues $queue): void
    {
        $cacheKey = $this->getQueueCacheKey($queue->integration_id);

        $data = [
            'integration_id' => $queue->integration_id,
            'status' => $queue->status,
            'priority' => $queue->priority,
            'started_at' => $queue->started_at,
            'completed_at' => $queue->completed_at,
            'ended_at' => $queue->ended_at,
            'execution_time' => $queue->execution_time,
            'error_message' => $queue->error_message,
            'attempts' => $queue->attempts
        ];

        Cache::put($cacheKey, $data, self::SHORT_TTL);

        Log::debug("Queue status cached", [
            'integration_id' => $queue->integration_id,
            'status' => $queue->status,
            'cache_key' => $cacheKey
        ]);
    }

    public function getCachedQueueStatus(int $integrationId): ?array
    {
        $cacheKey = $this->getQueueCacheKey($integrationId);
        return Cache::get($cacheKey);
    }

    public function cacheProcessingMetrics(int $integrationId, array $metrics): void
    {
        $cacheKey = $this->getMetricsCacheKey($integrationId);

        $data = array_merge($metrics, [
            'cached_at' => now(),
            'integration_id' => $integrationId
        ]);

        Cache::put($cacheKey, $data, self::DEFAULT_TTL);

        Log::debug("Processing metrics cached", [
            'integration_id' => $integrationId,
            'metrics' => $metrics
        ]);
    }

    public function getCachedMetrics(int $integrationId): ?array
    {
        $cacheKey = $this->getMetricsCacheKey($integrationId);
        return Cache::get($cacheKey);
    }

    public function cacheXmlContent(int $integrationId, string $xmlContent, ?int $ttl = null): void
    {
        $cacheKey = $this->getXmlCacheKey($integrationId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        Cache::put($cacheKey, $xmlContent, $ttl);

        Log::debug("XML content cached", [
            'integration_id' => $integrationId,
            'content_size' => strlen($xmlContent),
            'ttl' => $ttl
        ]);
    }

    public function getCachedXmlContent(int $integrationId): ?string
    {
        $cacheKey = $this->getXmlCacheKey($integrationId);
        $content = Cache::get($cacheKey);

        if ($content) {
            Log::debug("XML content loaded from cache", [
                'integration_id' => $integrationId,
                'content_size' => strlen($content)
            ]);
        }

        return $content;
    }

    public function cacheSystemStats(array $stats): void
    {
        $cacheKey = 'integration_system_stats';
        Cache::put($cacheKey, $stats, self::SHORT_TTL);

        Log::debug("System stats cached", ['stats' => $stats]);
    }

    public function getCachedSystemStats(): ?array
    {
        return Cache::get('integration_system_stats');
    }

    public function invalidateIntegrationCache(int $integrationId): void
    {
        $keys = [
            $this->getIntegrationCacheKey($integrationId),
            $this->getQueueCacheKey($integrationId),
            $this->getMetricsCacheKey($integrationId),
            $this->getXmlCacheKey($integrationId)
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        Log::info("Integration cache invalidated", [
            'integration_id' => $integrationId,
            'keys' => $keys
        ]);
    }

    public function invalidateAllCache(): void
    {
        $pattern = self::CACHE_PREFIX . '*';

        
        
        Log::info("All integration cache invalidated", ['pattern' => $pattern]);
    }

    public function warmUpCache(array $integrationIds): void
    {
        Log::info("Starting cache warm-up", ['integration_count' => count($integrationIds)]);

        $warmedUp = 0;
        foreach ($integrationIds as $integrationId) {
            try {
                $integration = Integracao::with('user')->find($integrationId);
                if ($integration) {
                    $this->cacheIntegrationData($integration);
                    $warmedUp++;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to warm up cache for integration", [
                    'integration_id' => $integrationId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info("Cache warm-up completed", [
            'requested' => count($integrationIds),
            'warmed_up' => $warmedUp
        ]);
    }

    private function getIntegrationCacheKey(int $integrationId): string
    {
        return self::CACHE_PREFIX . "data_{$integrationId}";
    }

    private function getQueueCacheKey(int $integrationId): string
    {
        return self::CACHE_PREFIX . "queue_{$integrationId}";
    }

    private function getMetricsCacheKey(int $integrationId): string
    {
        return self::CACHE_PREFIX . "metrics_{$integrationId}";
    }

    private function getXmlCacheKey(int $integrationId): string
    {
        return self::CACHE_PREFIX . "xml_{$integrationId}";
    }

    public function getCacheStats(): array
    {
        return [
            'cache_prefix' => self::CACHE_PREFIX,
            'default_ttl' => self::DEFAULT_TTL,
            'long_ttl' => self::LONG_TTL,
            'short_ttl' => self::SHORT_TTL,
            'cache_driver' => config('cache.default'),
            'redis_connection' => config('cache.stores.redis.connection', 'default')
        ];
    }
}
