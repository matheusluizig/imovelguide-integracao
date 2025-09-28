<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Gerenciador inteligente de slots de integração
 *
 * Responsabilidades:
 * - Controle de concorrência via Redis
 * - Auto-limpeza de slots órfãos
 * - Detecção de integrações travadas
 * - Recuperação automática de falhas
 */
class IntegrationSlotManager
{
    private const ACTIVE_INTEGRATIONS_SET_KEY = 'imovelguide_database_active_integrations';
    private const ACTIVE_INTEGRATIONS_COUNT_KEY = 'imovelguide_database_active_integrations_count';
    private const SLOT_TIMEOUT_KEY = 'imovelguide_integration_slot_timeout';
    private const ACTIVE_INTEGRATIONS_TTL = 3600; // 1 hora
    private const MAX_CONCURRENT_INTEGRATIONS = 6;
    private const STUCK_THRESHOLD_MINUTES = 10; // Reduzido para 10 minutos
    private const SLOT_TIMEOUT_SECONDS = 1800; // 30 minutos timeout automático

    private const ACQUIRE_SLOT_LUA = <<<'LUA'
local activeSetKey = KEYS[1]
local counterKey = KEYS[2]
local timeoutKey = KEYS[3]
local integrationId = ARGV[1]
local maxSlots = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])
local timeoutSeconds = tonumber(ARGV[4])
local currentTime = tonumber(ARGV[5])

-- Verificar se já existe e se não expirou
local existingTimeout = redis.call('HGET', timeoutKey, integrationId)
if existingTimeout and tonumber(existingTimeout) > currentTime then
    -- Verificar se é a mesma integração tentando reaquisição (permitir override)
    local isInActiveSet = redis.call('SISMEMBER', activeSetKey, integrationId)
    if isInActiveSet == 1 then
        -- Mesma integração - permitir reaquisição (pode ser retry)
        redis.call('SREM', activeSetKey, integrationId)
        redis.call('HDEL', timeoutKey, integrationId)
        local currentCount = tonumber(redis.call('GET', counterKey) or '0')
        if currentCount > 0 then
            redis.call('DECR', counterKey)
        end
    else
        -- Integração diferente - bloquear
        return {0, 'already_active', tonumber(existingTimeout) - currentTime}
    end
end

-- Limpar slot expirado se existir (com verificação de atomicidade)
if existingTimeout and tonumber(existingTimeout) <= currentTime then
    redis.call('SREM', activeSetKey, integrationId)
    local currentCount = tonumber(redis.call('GET', counterKey) or '0')
    if currentCount > 0 then
        redis.call('DECR', counterKey)
    end
    redis.call('HDEL', timeoutKey, integrationId)
end

-- Verificar limite de slots (com limpeza prévia de slots órfãos)
local currentCount = tonumber(redis.call('GET', counterKey) or '0')
local activeSet = redis.call('SMEMBERS', activeSetKey)
local actualCount = 0

-- Reconciliação: contar apenas slots válidos (não expirados)
for i, id in ipairs(activeSet) do
    local timeout = redis.call('HGET', timeoutKey, id)
    if timeout and tonumber(timeout) > currentTime then
        actualCount = actualCount + 1
    else
        -- Remover slot órfão
        redis.call('SREM', activeSetKey, id)
        redis.call('HDEL', timeoutKey, id)
    end
end

-- Ajustar contador se necessário
if actualCount ~= currentCount then
    redis.call('SET', counterKey, actualCount)
    currentCount = actualCount
end

-- Verificar limite após reconciliação
if currentCount >= maxSlots then
    return {currentCount, 'max_reached', currentCount}
end

-- Adquirir slot com timeout
redis.call('SADD', activeSetKey, integrationId)
redis.call('HSET', timeoutKey, integrationId, currentTime + timeoutSeconds)
local newCount = redis.call('INCR', counterKey)

-- Definir TTL nas chaves
if ttl > 0 then
    redis.call('EXPIRE', activeSetKey, ttl)
    redis.call('EXPIRE', counterKey, ttl)
    redis.call('EXPIRE', timeoutKey, ttl)
end

return {newCount, 'acquired', timeoutSeconds}
LUA;

    /**
     * Tenta adquirir um slot de integração com timeout automático
     */
    public function acquireSlot(int $integrationId): array
    {
        try {
            $redis = $this->getRedisWithRetry();

            // Executar limpeza prévia de slots órfãos
            $this->cleanupExpiredSlots($redis);

            $scriptResult = $this->executeAcquireSlotScript($redis, $integrationId);

            if (is_array($scriptResult) && count($scriptResult) >= 2) {
                $count = $scriptResult[0];
                $status = $scriptResult[1];
                $extra = $scriptResult[2] ?? null;

                if ($count > 0 && $status === 'acquired') {
                    return [
                        'acquired' => true,
                        'count' => $count,
                        'timeout_seconds' => $extra
                    ];
                }

                // Não conseguiu adquirir - tentar autocorreção rápida
                Log::channel('integration')->warning('❌ Could not acquire slot', [
                    'integration_id' => $integrationId,
                    'reason' => $status,
                    'details' => $extra
                ]);

                if (in_array($status, ['max_reached', 'already_active'])) {
                    $this->quickReconcileForAcquire($redis, $integrationId);
                    $retry = $this->executeAcquireSlotScript($redis, $integrationId);
                    if (is_array($retry) && ($retry[0] ?? 0) > 0 && ($retry[1] ?? '') === 'acquired') {
                        return [
                            'acquired' => true,
                            'count' => $retry[0],
                            'timeout_seconds' => $retry[2] ?? null
                        ];
                    }
                }

                return [
                    'acquired' => false,
                    'reason' => $status,
                    'details' => $extra
                ];
            }

            return ['acquired' => false, 'reason' => 'script_error'];

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error acquiring integration slot", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);

            return ['acquired' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Libera um slot de integração
     */
    public function releaseSlot(int $integrationId): void
    {
        try {
            $redis = $this->getRedisWithRetry();

            $wasActive = $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
            $timeoutRemoved = $redis->hdel(self::SLOT_TIMEOUT_KEY, $integrationId);

            if ($wasActive || $timeoutRemoved) {
                $this->syncActiveCount($redis);
            }

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error releasing integration slot", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);

            // CRÍTICO: Tentar fallback para liberar slot
            $this->forceReleaseSlotFallback($integrationId);
        }
    }

    /**
     * Fallback para forçar liberação de slot em caso de falha
     */
    private function forceReleaseSlotFallback(int $integrationId): void
    {
        try {
            // Tentar conexão Redis alternativa
            $redis = $this->getRedisWithRetry();

            // Forçar remoção usando comandos individuais
            $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
            $redis->hdel(self::SLOT_TIMEOUT_KEY, $integrationId);

            // Ajustar contador manualmente
            $currentCount = (int) ($redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY) ?: 0);
            if ($currentCount > 0) {
                $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, max(0, $currentCount - 1));
            }

            Log::channel('integration')->warning("⚠️ SLOT: Fallback release executed", [
                'integration_id' => $integrationId,
                'method' => 'force_fallback'
            ]);

        } catch (\Exception $fallbackError) {
            Log::channel('integration')->error("💀 SLOT: CRITICAL - Fallback also failed", [
                'integration_id' => $integrationId,
                'fallback_error' => $fallbackError->getMessage()
            ]);
        }
    }

    /**
     * Sincronizar estado Redis com DB para uma integração específica
     */
    public function syncRedisWithDb(int $integrationId): bool
    {
        try {
            $redis = $this->getRedisWithRetry();
            // Verificar se integração está processando no DB
            $queue = \App\Integracao\Domain\Entities\IntegrationsQueues::where('integration_id', $integrationId)
                ->where('status', \App\Integracao\Domain\Entities\IntegrationsQueues::STATUS_IN_PROCESS)
                ->first();

            $isInRedis = $redis->sismember(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
            $hasTimeout = $redis->hexists(self::SLOT_TIMEOUT_KEY, $integrationId);

            if ($queue && !$isInRedis) {
                // Integração está no DB mas não no Redis - adicionar ao Redis
                $this->addIntegrationToRedis($redis, $integrationId);
                return true;
            } elseif (!$queue && $isInRedis) {
                // Integração está no Redis mas não no DB - remover do Redis
                $this->removeIntegrationFromRedis($redis, $integrationId);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error syncing Redis with DB", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
            
            // CRÍTICO: Tentar retry da sincronização
            return $this->retrySyncRedisWithDb($integrationId);
        }
    }

    /**
     * Retry da sincronização Redis-DB
     */
    private function retrySyncRedisWithDb(int $integrationId): bool
    {
        try {
            Log::channel('integration')->warning("🔄 SYNC: Retrying Redis-DB sync", [
                'integration_id' => $integrationId,
                'attempt' => 1
            ]);
            
            // Aguardar 1 segundo antes do retry
            usleep(1000000); // 1 segundo
            
            // Tentar novamente
            $redis = $this->getRedisWithRetry();
            
            $queue = \App\Integracao\Domain\Entities\IntegrationsQueues::where('integration_id', $integrationId)
                ->where('status', \App\Integracao\Domain\Entities\IntegrationsQueues::STATUS_IN_PROCESS)
                ->first();

            $isInRedis = $redis->sismember(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);

                if ($queue && !$isInRedis) {
                    $this->addIntegrationToRedis($redis, $integrationId);
                    return true;
                } elseif (!$queue && $isInRedis) {
                    $this->removeIntegrationFromRedis($redis, $integrationId);
                    return true;
                }

            return false;

        } catch (\Exception $retryError) {
            Log::channel('integration')->error("💀 SYNC: CRITICAL - Retry also failed", [
                'integration_id' => $integrationId,
                'retry_error' => $retryError->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Adicionar integração ao Redis
     */
    private function addIntegrationToRedis($redis, int $integrationId): void
    {
        $timeout = time() + self::SLOT_TIMEOUT_SECONDS;
        $redis->sadd(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
        $redis->hset(self::SLOT_TIMEOUT_KEY, $integrationId, $timeout);
        $this->syncActiveCount($redis);
        // Definir TTL
        $redis->expire(self::ACTIVE_INTEGRATIONS_SET_KEY, self::ACTIVE_INTEGRATIONS_TTL);
        $redis->expire(self::ACTIVE_INTEGRATIONS_COUNT_KEY, self::ACTIVE_INTEGRATIONS_TTL);
        $redis->expire(self::SLOT_TIMEOUT_KEY, self::ACTIVE_INTEGRATIONS_TTL);
    }

    /**
     * Remover integração do Redis
     */
    private function removeIntegrationFromRedis($redis, int $integrationId): void
    {
        $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
        $redis->hdel(self::SLOT_TIMEOUT_KEY, $integrationId);
        $this->syncActiveCount($redis);
    }

    /**
     * Limpeza automática de slots expirados
     */
    public function cleanupExpiredSlots($redis): void
    {
        $lockKey = "cleanup_expired_slots_lock";

        try {
            $lock = Cache::lock($lockKey, 30);
            if (!$lock->get()) {
                return;
            }

            try {
                $currentTime = time();
                $expiredSlots = [];

                // Obter todos os timeouts
                $allTimeouts = $this->redisCallWithRetry(fn() => $redis->hgetall(self::SLOT_TIMEOUT_KEY));

                foreach ($allTimeouts as $integrationId => $expirationTime) {
                    if ($currentTime > (int) $expirationTime) {
                        $expiredSlots[] = $integrationId;
                    }
                }

                if (!empty($expiredSlots)) {
                    foreach ($expiredSlots as $integrationId) {
                        $this->redisCallWithRetry(fn() => $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId));
                        $this->redisCallWithRetry(fn() => $redis->hdel(self::SLOT_TIMEOUT_KEY, $integrationId));
                        $this->resetExpiredIntegration($integrationId);
                    }

                    $this->syncActiveCount($redis);
                }

            } finally {
                $lock->release();
            }

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error cleaning expired slots", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reset integração expirada para pending
     */
    private function resetExpiredIntegration(int $integrationId): void
    {
        try {
            $queue = IntegrationsQueues::where('integration_id', $integrationId)
                ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->first();

            if ($queue) {
                DB::transaction(function() use ($queue, $integrationId) {
                    $queue->update([
                        'status' => IntegrationsQueues::STATUS_PENDING,
                        'started_at' => null,
                        'updated_at' => now(),
                        'error_message' => 'Auto-reset: slot timeout expirado'
                    ]);

                    $integration = \App\Integracao\Domain\Entities\Integracao::find($integrationId);
                    if ($integration && in_array($integration->status, [6, 7, 8])) {
                        $integration->update(['status' => 3]); // XML_STATUS_IN_ANALYSIS
                    }
                });

            }

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error resetting expired integration", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Limpeza automática de slots órfãos (inteligente) - MANTIDA PARA COMPATIBILIDADE
     */
    public function cleanupOrphanedSlots($redis): void
    {
        $lockKey = "cleanup_orphaned_slots_lock";

        try {
            // Usar cache lock em vez de Redis para evitar conflitos
            $lock = Cache::lock($lockKey, 30);
            if (!$lock->get()) {
                return; // Limpeza já em andamento
            }

            try {
                $activeIntegrations = $redis->smembers(self::ACTIVE_INTEGRATIONS_SET_KEY);
                $currentCount = (int) ($redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY) ?: 0);

                if (empty($activeIntegrations)) {
                    if ($currentCount > 0) {
                        $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, 0);
                    Log::channel('integration')->warning('🔧 Reset integration count to 0 - no active integrations');
                    }
                    return;
                }

                $orphanedSlots = [];
                $stuckSlots = [];

                foreach ($activeIntegrations as $integrationId) {
                    $queue = IntegrationsQueues::where('integration_id', $integrationId)
                        ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                        ->first();

                    if (!$queue) {
                        $orphanedSlots[] = $integrationId;
                    } else {
                        // Verificar se está travada há muito tempo
                        $minutesProcessing = $queue->started_at ? now()->diffInMinutes($queue->started_at) : 0;
                        if ($minutesProcessing > self::STUCK_THRESHOLD_MINUTES) {
                            $stuckSlots[] = [
                                'id' => $integrationId,
                                'minutes' => $minutesProcessing,
                                'queue' => $queue
                            ];
                        }
                    }
                }

                // Limpar slots órfãos
                if (!empty($orphanedSlots)) {
                    Log::channel('integration')->warning("🧹 Cleaning orphaned slots", [
                        'orphaned_slots' => $orphanedSlots
                    ]);

                    foreach ($orphanedSlots as $integrationId) {
                        $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
                    }

                    $newCount = max(0, $currentCount - count($orphanedSlots));
                    $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, $newCount);
                }

                // Resetar integrações travadas
                if (!empty($stuckSlots)) {
                    Log::channel('integration')->warning("🔄 Resetting stuck integrations", [
                        'stuck_count' => count($stuckSlots)
                    ]);

                    foreach ($stuckSlots as $stuckSlot) {
                        $this->resetStuckIntegration($stuckSlot, $redis);
                    }
                }

            } finally {
                $lock->release();
            }

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error in orphaned slots cleanup", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reset inteligente de integração travada
     */
    private function resetStuckIntegration(array $stuckSlot, $redis): void
    {
        try {
            $integrationId = $stuckSlot['id'];
            $queue = $stuckSlot['queue'];

            // Atualizar status no banco
            \DB::transaction(function() use ($queue, $integrationId, $stuckSlot) {
                $queue->update([
                    'status' => IntegrationsQueues::STATUS_PENDING,
                    'started_at' => null,
                    'updated_at' => now(),
                    'error_message' => "Auto-reset: travado por {$stuckSlot['minutes']} minutos"
                ]);

                // Resetar status da integração XML se necessário
                $integration = \App\Integracao\Domain\Entities\Integracao::find($integrationId);
                if ($integration && in_array($integration->status, [6, 7, 8])) {
                    $integration->update(['status' => 3]); // XML_STATUS_IN_ANALYSIS
                }
            });

            // Liberar slot no Redis
            $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
            $this->syncActiveCount($redis);

            Log::channel('integration')->warning('🔄 Stuck integration auto-reset', [
                'integration_id' => $integrationId,
                'minutes_stuck' => $stuckSlot['minutes']
            ]);

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error resetting stuck integration", [
                'integration_id' => $stuckSlot['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Obter estatísticas dos slots
     */
    public function getSlotStats(): array
    {
        try {
            $redis = $this->getRedisWithRetry();

            $activeIntegrations = $this->redisCallWithRetry(fn() => $redis->smembers(self::ACTIVE_INTEGRATIONS_SET_KEY));
            $currentCount = (int) ($this->redisCallWithRetry(fn() => $redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY)) ?: 0);

            return [
                'active_slots' => count($activeIntegrations),
                'counter' => $currentCount,
                'max_slots' => self::MAX_CONCURRENT_INTEGRATIONS,
                'available_slots' => self::MAX_CONCURRENT_INTEGRATIONS - $currentCount,
                'active_integration_ids' => $activeIntegrations
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    private function executeAcquireSlotScript($redis, int $integrationId): array
    {
        $result = $this->evalLuaScript(
            $redis,
            self::ACQUIRE_SLOT_LUA,
            [
                self::ACTIVE_INTEGRATIONS_SET_KEY,
                self::ACTIVE_INTEGRATIONS_COUNT_KEY,
                self::SLOT_TIMEOUT_KEY
            ],
            [
                (string) $integrationId,
                self::MAX_CONCURRENT_INTEGRATIONS,
                self::ACTIVE_INTEGRATIONS_TTL,
                self::SLOT_TIMEOUT_SECONDS,
                time()
            ]
        );

        // Resultado do script Lua é um array [count, status, extra]
        return is_array($result) ? $result : [0, 'script_error'];
    }

    private function evalLuaScript($redis, string $script, array $keys, array $arguments)
    {
        return $this->redisCallWithRetry(function() use ($redis, $script, $keys, $arguments) {
            if ($redis instanceof PhpRedisConnection) {
                return $redis->eval($script, array_merge($keys, $arguments), count($keys));
            }
            return $redis->eval($script, count($keys), ...$keys, ...$arguments);
        });
    }

    private function quickReconcileForAcquire($redis, int $integrationId): void
    {
        try {
            $active = $this->redisCallWithRetry(fn() => $redis->smembers(self::ACTIVE_INTEGRATIONS_SET_KEY));
            $count = (int) ($this->redisCallWithRetry(fn() => $redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY)) ?: 0);
            $timeouts = $this->redisCallWithRetry(fn() => $redis->hgetall(self::SLOT_TIMEOUT_KEY));

            $now = time();
            foreach (($timeouts ?: []) as $id => $expiration) {
                if ($now > (int) $expiration) {
                    $this->redisCallWithRetry(fn() => $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $id));
                    $this->redisCallWithRetry(fn() => $redis->hdel(self::SLOT_TIMEOUT_KEY, $id));
                }
            }

            $this->syncActiveCount($redis);
        } catch (\Throwable $t) {
            Log::channel('integration')->warning('quickReconcileForAcquire failed', [
                'integration_id' => $integrationId,
                'error' => $t->getMessage()
            ]);
        }
    }

    private function syncActiveCount($redis): int
    {
        $count = (int) $this->redisCallWithRetry(fn() => $redis->scard(self::ACTIVE_INTEGRATIONS_SET_KEY));
        $this->redisCallWithRetry(fn() => $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, $count));

        return $count;
    }

    private function getRedisWithRetry()
    {
        return $this->retry(function() {
            return app('redis')->connection();
        });
    }

    private function redisCallWithRetry(callable $fn)
    {
        return $this->retry($fn);
    }

    private function retry(callable $fn)
    {
        $attempts = 0;
        $lastException = null;
        while ($attempts < 3) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $lastException = $e;
                usleep(200000);
                $attempts++;
            }
        }
        throw $lastException;
    }
}