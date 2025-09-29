<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Domain\Entities\Integracao;

/**
 * Sistema de heartbeat para detectar integrações travadas em tempo real
 *
 * Responsabilidades:
 * - Monitorar progresso das integrações
 * - Detectar travamentos rapidamente (2-5 minutos)
 * - Auto-recuperação de integrações presas
 * - Logging detalhado de atividade
 */
class IntegrationHeartbeat
{
    private const HEARTBEAT_KEY = 'integration_heartbeat_';
    private const HEARTBEAT_TTL = 600; // 10 minutos - deve ser maior que STUCK_THRESHOLD
    private const STUCK_THRESHOLD_SECONDS = 300; // 5 minutos sem heartbeat = travado
    private const HEARTBEAT_RENEWAL_INTERVAL = 60; // Renovar heartbeat a cada 1 minuto

    /**
     * Iniciar heartbeat para uma integração
     */
    public function startHeartbeat(int $integrationId): void
    {
        try {
            $key = self::HEARTBEAT_KEY . $integrationId;
            $redis = $this->getRedisWithRetry();

            $data = [
                'integration_id' => $integrationId,
                'started_at' => time(),
                'last_heartbeat' => time(),
                'worker_pid' => getmypid(),
                'status' => 'started',
                'current_step' => 'initialization',
                'progress' => ['step' => 'start', 'percentage' => 0]
            ];

            $redis->setex($key, self::HEARTBEAT_TTL, json_encode($data));

            Log::channel('integration')->debug("💓 Heartbeat started", [
                'integration_id' => $integrationId,
                'worker_pid' => getmypid()
            ]);

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Failed to start heartbeat", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Atualizar heartbeat com progresso atual
     */
    public function updateHeartbeat(int $integrationId, string $step, array $progress = []): void
    {
        try {
            $key = self::HEARTBEAT_KEY . $integrationId;
            $redis = $this->getRedisWithRetry();

            $existingData = $this->redisCallWithRetry(fn() => $redis->get($key));
            $data = $existingData ? json_decode($existingData, true) : [];

            $currentTime = time();
            $lastHeartbeat = $data['last_heartbeat'] ?? $currentTime;
            // Verificar se precisa renovar TTL (evita atualizações desnecessárias)
            $needsRenewal = ($currentTime - $lastHeartbeat) >= self::HEARTBEAT_RENEWAL_INTERVAL;

            $data = array_merge($data, [
                'last_heartbeat' => $currentTime,
                'current_step' => $step,
                'progress' => array_merge($data['progress'] ?? [], $progress),
                'updated_count' => ($data['updated_count'] ?? 0) + 1,
                'ttl_renewed' => $needsRenewal
            ]);

            // Renovar TTL apenas quando necessário
            if ($needsRenewal) {
                $this->redisCallWithRetry(fn() => $redis->setex($key, self::HEARTBEAT_TTL, json_encode($data)));
                Log::channel('integration')->debug("💓 Heartbeat TTL renewed", [
                    'integration_id' => $integrationId,
                    'step' => $step
                ]);
            } else {
                // Apenas atualizar dados, manter TTL existente
                $this->redisCallWithRetry(fn() => $redis->set($key, json_encode($data)));
            }

            Log::channel('integration')->debug("💓 Heartbeat updated", [
                'integration_id' => $integrationId,
                'step' => $step,
                'progress' => $progress,
                'needs_renewal' => $needsRenewal
            ]);

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Failed to update heartbeat", [
                'integration_id' => $integrationId,
                'step' => $step,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Parar heartbeat (integração concluída)
     */
    public function stopHeartbeat(int $integrationId, string $finalStatus = 'completed'): void
    {
        try {
            $key = self::HEARTBEAT_KEY . $integrationId;
            $redis = $this->getRedisWithRetry();

            // Marcar como finalizado antes de deletar (para audit)
            $existingData = $this->redisCallWithRetry(fn() => $redis->get($key));
            if ($existingData) {
                $data = json_decode($existingData, true);
                $data['final_status'] = $finalStatus;
                $data['ended_at'] = time();

                // Manter por mais 30 segundos para auditoria
                $this->redisCallWithRetry(fn() => $redis->setex($key . '_final', 30, json_encode($data)));
            }

            $this->redisCallWithRetry(fn() => $redis->del($key));

            Log::channel('integration')->debug("💓 Heartbeat stopped", [
                'integration_id' => $integrationId,
                'final_status' => $finalStatus
            ]);

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Failed to stop heartbeat", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Verificar integrações travadas e fazer auto-reset
     */
    public function checkStuckIntegrations(): array
    {
        try {
            $redis = $this->getRedisWithRetry();
            $pattern = self::HEARTBEAT_KEY . '*';
            $stuckIntegrations = [];
            $currentTime = time();

            $keys = $this->redisCallWithRetry(fn() => $redis->keys($pattern));
            if (empty($keys)) {
                return [];
            }

            foreach ($keys as $key) {
                // Pular chaves finalizadas
                if (str_ends_with($key, '_final')) {
                    continue;
                }

                $data = $this->redisCallWithRetry(fn() => $redis->get($key));
                if (!$data) {
                    continue;
                }

                $heartbeatData = json_decode($data, true);
                if (!$heartbeatData || !isset($heartbeatData['last_heartbeat'])) {
                    continue;
                }

                $secondsSinceLastHeartbeat = $currentTime - $heartbeatData['last_heartbeat'];

                // Se não há heartbeat há mais do que o threshold
                if ($secondsSinceLastHeartbeat > self::STUCK_THRESHOLD_SECONDS) {
                    $integrationId = $heartbeatData['integration_id'];

                    $hasRecent = $this->hasRecentActivityByIntegration($integrationId);
                    if ($hasRecent) {
                        continue;
                    }

                    $stuckInfo = [
                        'integration_id' => $integrationId,
                        'stuck_for_seconds' => $secondsSinceLastHeartbeat,
                        'current_step' => $heartbeatData['current_step'] ?? 'unknown',
                        'worker_pid' => $heartbeatData['worker_pid'] ?? null,
                        'started_at' => $heartbeatData['started_at'] ?? null,
                        'last_progress' => $heartbeatData['progress'] ?? []
                    ];

                    $stuckIntegrations[] = $stuckInfo;

                    Log::channel('integration')->warning("🚨 Stuck integration detected", $stuckInfo);

                    // Auto-reset da integração
                    $resetSuccess = $this->autoResetStuckIntegration($integrationId, $stuckInfo);

                    if ($resetSuccess) {
                        // Limpar heartbeat da integração resetada
                        $this->redisCallWithRetry(fn() => $redis->del($key));
                    }
                }
            }

            if (!empty($stuckIntegrations)) {
                Log::channel('integration')->warning("🚨 Total stuck integrations found", [
                    'count' => count($stuckIntegrations),
                    'integration_ids' => array_column($stuckIntegrations, 'integration_id')
                ]);
            }

            // Reconciliar slots após resets e inconsistências
            try {
                app(IntegrationSlotManager::class)->cleanupExpiredSlots($redis);
                app(IntegrationSlotManager::class)->cleanupOrphanedSlots($redis);
            } catch (\Throwable $t) {
                Log::channel('integration')->warning('Cleanup after heartbeat check failed', [
                    'error' => $t->getMessage()
                ]);
            }

            return $stuckIntegrations;

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error checking stuck integrations", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Auto-reset de integração travada (com reset completo da queue)
     */
    private function autoResetStuckIntegration(int $integrationId, array $stuckInfo): bool
    {
        try {
            Log::channel('integration')->warning("🔄 Auto-resetting stuck integration", [
                'integration_id' => $integrationId,
                'stuck_for_seconds' => $stuckInfo['stuck_for_seconds']
            ]);

            return DB::transaction(function() use ($integrationId, $stuckInfo) {
                // 1. Encontrar queue
                $queue = IntegrationsQueues::lockForUpdate()
                    ->where('integration_id', $integrationId)
                    ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                    ->first();

                if (!$queue) {
                    Log::channel('integration')->warning("Queue não encontrada para reset", [
                        'integration_id' => $integrationId
                    ]);
                    return false;
                }

                // 2. Reset completo da queue usando StatusManager (zera TODOS os campos)
                $statusManager = app(\App\Integracao\Application\Services\IntegrationStatusManager::class);
                $resetMessage = "Auto-reset: sem heartbeat por {$stuckInfo['stuck_for_seconds']}s - step: {$stuckInfo['current_step']}";

                $statusManager->resetToPending($queue, $resetMessage);

                // Incrementar tentativas mantendo histórico
                $queue->update([
                    'attempts' => ($queue->attempts ?? 0) + 1,
                    'last_error_step' => 'heartbeat_timeout'
                ]);

                // 3. Reset status da integração se necessário
                $integration = Integracao::lockForUpdate()->find($integrationId);
                if ($integration && in_array($integration->status, [6, 7, 8])) {
                    $integration->update(['status' => 3]); // XML_STATUS_IN_ANALYSIS
                }

                // 4. Liberar slot no Redis se estiver ocupado
                $slotManager = app(IntegrationSlotManager::class);
                $slotManager->releaseSlot($integrationId);

                Log::channel('integration')->info("✅ Stuck integration auto-reset completed with full queue reset", [
                    'integration_id' => $integrationId,
                    'queue_id' => $queue->id,
                    'attempts' => $queue->attempts,
                    'reset_reason' => 'heartbeat_timeout'
                ]);

                return true;
            });

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Failed to auto-reset stuck integration", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obter status de heartbeats ativos
     */
    public function getActiveHeartbeats(): array
    {
        try {
            $redis = $this->getRedisWithRetry();
            $pattern = self::HEARTBEAT_KEY . '*';
            $activeHeartbeats = [];

            $keys = $this->redisCallWithRetry(fn() => $redis->keys($pattern));
            if (empty($keys)) {
                return [];
            }

            foreach ($keys as $key) {
                if (str_ends_with($key, '_final')) {
                    continue;
                }

                $data = $this->redisCallWithRetry(fn() => $redis->get($key));
                if ($data) {
                    $heartbeatData = json_decode($data, true);
                    if ($heartbeatData) {
                        $activeHeartbeats[] = array_merge($heartbeatData, [
                            'seconds_since_last_heartbeat' => time() - $heartbeatData['last_heartbeat']
                        ]);
                    }
                }
            }

            return $activeHeartbeats;

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error getting active heartbeats", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Limpar heartbeats expirados/órfãos
     */
    public function cleanupExpiredHeartbeats(): int
    {
        try {
            $redis = $this->getRedisWithRetry();
            $pattern = self::HEARTBEAT_KEY . '*';
            $cleaned = 0;
            $currentTime = time();

            $keys = $this->redisCallWithRetry(fn() => $redis->keys($pattern));
            if (empty($keys)) {
                return 0;
            }

            foreach ($keys as $key) {
                $data = $this->redisCallWithRetry(fn() => $redis->get($key));
                if (!$data) {
                    $this->redisCallWithRetry(fn() => $redis->del($key));
                    $cleaned++;
                    continue;
                }

                $heartbeatData = json_decode($data, true);
                if (!$heartbeatData || !isset($heartbeatData['last_heartbeat'])) {
                    $this->redisCallWithRetry(fn() => $redis->del($key));
                    $this->redisCallWithRetry(fn() => $redis->del($key));
                    $cleaned++;
                    continue;
                }

                // Limpar heartbeats muito antigos (> 10 minutos)
                $secondsSinceLastHeartbeat = $currentTime - $heartbeatData['last_heartbeat'];
                if ($secondsSinceLastHeartbeat > 600) {
                    $this->redisCallWithRetry(fn() => $redis->del($key));
                    $cleaned++;
                }
            }

            if ($cleaned > 0) {
                Log::channel('integration')->info("🧹 Cleaned expired heartbeats", [
                    'cleaned_count' => $cleaned
                ]);
            }

            return $cleaned;

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Error cleaning expired heartbeats", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function hasRecentActivityByIntegration(int $integrationId): bool
    {
        try {
            $integration = Integracao::select('id', 'user_id')->find($integrationId);
            if (!$integration) {
                return false;
            }

            return DB::table('anuncios as A')
                ->where('A.user_id', $integration->user_id)
                ->where('A.updated_at', '>=', now()->subMinutes(10))
                ->exists();
        } catch (\Exception $e) {
            return true;
        }
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