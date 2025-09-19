<?php

namespace App\Integracao\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Services\IntegrationProcessingService;
use App\Integracao\Application\Services\IntegrationLoggingService;
use App\Integracao\Application\Services\XMLIntegrationLoggerService;
use App\Integracao\Infrastructure\Repositories\IntegrationRepository;
use Carbon\Carbon;

class ProcessIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $integrationId;
    public $timeout = 86400; 
    public $tries = 5; // Aumentando o número de tentativas
    public $backoff = [60, 300, 900, 3600, 7200]; // 1min, 5min, 15min, 1h, 2h

    public function __construct(int $integrationId, ?string $queueName = null)
    {
        $this->integrationId = $integrationId;
        $this->onConnection('redis');
        $this->onQueue($queueName ?? 'normal-integrations');
    }

    /**
     * Determina o nome da fila baseado na prioridade da integração
     */
    private function determineQueueName(int $integrationId): string
    {
        try {
            $queue = IntegrationsQueues::where('integration_id', $integrationId)->first();
            if ($queue) {
                return match ($queue->priority) {
                    IntegrationsQueues::PRIORITY_PLAN => 'priority-integrations',
                    IntegrationsQueues::PRIORITY_LEVEL => 'level-integrations',
                    default => 'normal-integrations'
                };
            }
        } catch (\Exception $e) {
            Log::warning('Failed to determine queue name, using default', [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }

        return 'normal-integrations';
    }

    public function handle()
    {
        // Configurar limites para integrações grandes (apenas quando necessário)
        ini_set('memory_limit', '2G');
        set_time_limit(0);
        $startTime = microtime(true);
        $integration = null;
        $queue = null;
        $correlationId = null;

        // Log da execução do job
        Log::info("ProcessIntegrationJob started", [
            'integration_id' => $this->integrationId,
            'attempt' => $this->attempts(),
            'job_id' => $this->job ? $this->job->getJobId() : 'unknown'
        ]);

        // Controle de concorrência - máximo 3 integrações simultâneas
        if (!$this->acquireIntegrationSlot()) {
            Log::info("Integration slot not available, releasing job for retry", ['integration_id' => $this->integrationId]);
            $this->release(60); // Retry em 60 segundos
            return;
        }

        try {
            // Buscar integração com relacionamentos necessários
            $integration = Integracao::with(['user'])->find($this->integrationId);
            if (!$integration) {
                Log::error("Integration not found, job will fail", ['integration_id' => $this->integrationId]);
                throw new \RuntimeException("Integration {$this->integrationId} not found");
            }

            // Buscar fila separadamente
            $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();
            if (!$queue) {
                Log::error("Queue not found for integration, job will fail", ['integration_id' => $this->integrationId]);
                throw new \RuntimeException("Queue not found for integration {$this->integrationId}");
            }

            // Verificar se já está processando
            if ($queue->status === IntegrationsQueues::STATUS_IN_PROCESS) {
                Log::info("Integration already processing, releasing job for retry", ['integration_id' => $this->integrationId]);
                $this->release(60); // Retry em 60 segundos
                return;
            }

            // Inicializar sistema de logging estruturado
            $loggingService = app(IntegrationLoggingService::class);
            $correlationId = $loggingService->logIntegrationStart($integration, [
                'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                'attempt' => $this->attempts(),
                'queue' => $this->job ? $this->job->getQueue() : 'unknown'
            ]);

            // Cache para evitar reprocessamento - ANTES da atualização de status
            $cacheKey = "integration_processing_{$this->integrationId}";
            $lock = null;

            try {
                $lock = Cache::lock($cacheKey, 21600); // 6 horas de lock - adequado para integrações grandes

                if (!$lock->get()) {
                    Log::info("Integration already being processed, releasing job for retry", ['integration_id' => $this->integrationId]);
                    $this->release(300); // Retry em 5 minutos
                    return;
                }

                // Atualizar status para processando
                $this->updateStatus($integration, $queue, IntegrationsQueues::STATUS_IN_PROCESS, Integracao::XML_STATUS_IN_UPDATE_BOTH);

                // Executar processamento completo
                $integrationService = app(IntegrationProcessingService::class);
                $result = $integrationService->processIntegration($integration);

                // Calcular tempo de execução
                $executionTime = microtime(true) - $startTime;

                if ($result['success']) {
                    // Transação única para updates de sucesso
                    DB::transaction(function() use ($integration, $queue, $result, $executionTime) {
                        $this->updateStatus($integration, $queue, IntegrationsQueues::STATUS_DONE, Integracao::XML_STATUS_INTEGRATED);
                        $queue->update([
                            'completed_at' => now(),
                            'ended_at' => now(),
                            'execution_time' => $executionTime
                        ]);
                    });

                    // Log de sucesso estruturado
                    $loggingService->logIntegrationSuccess($integration, $correlationId, $result['metrics'] ?? []);
                    // Log de performance
                    $loggingService->logPerformanceMetrics([
                        'integration_id' => $this->integrationId,
                        'execution_time' => $executionTime,
                        'processed_items' => $result['processed_items'] ?? 0,
                        'total_items' => $result['total_items'] ?? 0,
                        'success_rate' => $result['metrics']['success_rate'] ?? 0
                    ]);

                } else {
                    throw new \Exception($result['error'] ?? 'Unknown processing error');
                }
            } finally {
                // Liberar lock de processamento - sempre liberar
                if ($lock) {
                    $lock->release();
                }
            }

        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;
            // Log de erro estruturado
            if ($integration && $correlationId) {
                $loggingService = app(IntegrationLoggingService::class);
                $loggingService->logIntegrationError($integration, $correlationId, $e, [
                    'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                    'attempt' => $this->attempts(),
                    'execution_time' => $executionTime
                ]);
            }

            if ($integration && $queue) {
                // Transação única para updates de erro
                DB::transaction(function() use ($integration, $queue, $e, $executionTime, $correlationId) {
                    $this->updateStatus($integration, $queue, IntegrationsQueues::STATUS_STOPPED, Integracao::XML_STATUS_CRM_ERRO);
                    $queue->update([
                        'ended_at' => now(),
                        'execution_time' => $executionTime,
                        'error_message' => $e->getMessage(),
                        'last_error_step' => 'processing',
                        'error_details' => json_encode([
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'attempt' => $this->attempts(),
                            'correlation_id' => $correlationId
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]);
                });
            }

            // Re-throw para que o Laravel possa fazer retry
            throw $e;
        } finally {
            $this->releaseIntegrationSlot();

            // Liberar lock de processamento - sempre liberar
            if (isset($lock) && $lock) {
                $lock->release();
            }
        }
    }

    private function updateStatus(Integracao $integration, IntegrationsQueues $queue, int $queueStatus, int $integrationStatus): void
    {
        $queue->update([
            'status' => $queueStatus,
            'started_at' => $queueStatus === IntegrationsQueues::STATUS_IN_PROCESS ? now() : $queue->started_at,
            'updated_at' => now()
        ]);

        if ($integration->status !== $integrationStatus) {
            $integration->update(['status' => $integrationStatus]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Liberar slot de integração em caso de falha
        $this->releaseIntegrationSlot();

        Log::error("Integration job failed permanently", [
            'integration_id' => $this->integrationId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $integration = Integracao::find($this->integrationId);
        $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();

        if ($integration && $queue) {
            if ($exception instanceof \Predis\Connection\ConnectionException ||
                $exception instanceof \RedisException ||
                strpos($exception->getMessage(), 'redis') !== false ||
                strpos($exception->getMessage(), 'connection') !== false) {
                // Resetar tentativas para permitir uma nova tentativa
                $queue->update([
                    'attempts' => 0,
                    'error_message' => "Redis connection issue detected, job will be retried: " . $exception->getMessage(),
                    'last_error_step' => 'redis_connection_error',
                    'error_details' => json_encode([
                        'exception' => get_class($exception),
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'attempts' => $this->attempts(),
                        'action' => 'retry_scheduled'
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);

                // Reagendar para execução em 30 minutos
                dispatch(new self($this->integrationId))->delay(now()->addMinutes(30));

                Log::info("Integration job rescheduled due to Redis connection issues", [
                    'integration_id' => $this->integrationId
                ]);

                return;
            }

            $this->updateStatus($integration, $queue, IntegrationsQueues::STATUS_STOPPED, Integracao::XML_STATUS_CRM_ERRO);
            $queue->update([
                'ended_at' => now(),
                'error_message' => "Job failed after {$this->attempts()} attempts: " . $exception->getMessage(),
                'last_error_step' => 'job_failed',
                'error_details' => json_encode([
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'attempts' => $this->attempts()
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        }
    }

    /**
     * Adquire um slot de integração (máximo 3 simultâneas)
     */
    private function acquireIntegrationSlot(): bool
    {
        try {
            $redis = app('redis');
            
            // Verificar se já está processando
            $isActive = $redis->sismember('imovelguide_database_active_integrations', $this->integrationId);
            if ($isActive) {
                Log::info("Integration already active, slot not available", [
                    'integration_id' => $this->integrationId
                ]);
                return false;
            }

            // Verificar limite de concorrência primeiro
            $count = $redis->get('imovelguide_database_active_integrations_count') ?: 0;
            if ($count >= 3) {
                // Limpar slots órfãos apenas se o limite foi atingido
                $this->cleanupOrphanedSlots($redis);
                
                // Verificar novamente após limpeza
                $count = $redis->get('imovelguide_database_active_integrations_count') ?: 0;
                if ($count >= 3) {
                    Log::info("Max concurrent integrations reached, slot not available", [
                        'integration_id' => $this->integrationId,
                        'current_count' => $count
                    ]);
                    return false;
                }
            }

            // Adquirir slot
            $redis->multi();
            $redis->incr('imovelguide_database_active_integrations_count');
            $redis->expire('imovelguide_database_active_integrations_count', 3600);
            $redis->sadd('imovelguide_database_active_integrations', $this->integrationId);
            $redis->expire('imovelguide_database_active_integrations', 3600);
            $result = $redis->exec();

            if ($result) {
                Log::debug("Integration slot acquired successfully", [
                    'integration_id' => $this->integrationId
                ]);
                return true;
            }

            Log::debug("Failed to acquire integration slot - transaction failed", [
                'integration_id' => $this->integrationId
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Error acquiring integration slot', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Libera um slot de integração
     */
    private function releaseIntegrationSlot(): void
    {
        try {
            $redis = app('redis');
            
            // Remover da lista de ativos
            $wasActive = $redis->srem('imovelguide_database_active_integrations', $this->integrationId);
            
            if ($wasActive) {
                // Decrementar contador
                $count = $redis->decr('imovelguide_database_active_integrations_count');
                if ($count < 0) {
                    $redis->set('imovelguide_database_active_integrations_count', 0);
                }
                
                Log::debug("Integration slot released successfully", [
                    'integration_id' => $this->integrationId,
                    'remaining_count' => max(0, $count)
                ]);
            } else {
                Log::debug("Integration slot was not active", [
                    'integration_id' => $this->integrationId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error releasing integration slot', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Limpa slots órfãos (integrações que não estão mais processando)
     */
    private function cleanupOrphanedSlots($redis): void
    {
        try {
            // Usar lock para evitar race conditions durante limpeza
            $lockKey = "cleanup_orphaned_slots_lock";
            $lock = $redis->set($lockKey, 1, 'EX', 30, 'NX'); // Lock por 30 segundos
            
            if (!$lock) {
                Log::debug("Cleanup already in progress, skipping");
                return;
            }

            try {
                $activeIntegrations = $redis->smembers('imovelguide_database_active_integrations');
                $currentCount = $redis->get('imovelguide_database_active_integrations_count') ?: 0;
                
                Log::debug("Checking for orphaned slots", [
                    'active_integrations' => $activeIntegrations,
                    'current_count' => $currentCount
                ]);

                if (empty($activeIntegrations)) {
                    // Se não há slots ativos mas o contador não é zero, resetar
                    if ($currentCount > 0) {
                        $redis->set('imovelguide_database_active_integrations_count', 0);
                        Log::info("Reset integration count to 0 - no active integrations found");
                    }
                    return;
                }

                $orphanedSlots = [];
                foreach ($activeIntegrations as $integrationId) {
                    // Verificar se a integração ainda está em processamento no banco
                    $queue = IntegrationsQueues::where('integration_id', $integrationId)
                        ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                        ->first();

                    if (!$queue) {
                        $orphanedSlots[] = $integrationId;
                    }
                }

                if (!empty($orphanedSlots)) {
                    Log::info("Cleaning up orphaned integration slots", [
                        'orphaned_slots' => $orphanedSlots
                    ]);

                    // Usar transação para garantir consistência
                    $redis->multi();
                    foreach ($orphanedSlots as $integrationId) {
                        $redis->srem('imovelguide_database_active_integrations', $integrationId);
                    }
                    $redis->exec();

                    // Ajustar contador
                    $newCount = max(0, $currentCount - count($orphanedSlots));
                    $redis->set('imovelguide_database_active_integrations_count', $newCount);

                    Log::info("Orphaned slots cleaned up", [
                        'removed_slots' => count($orphanedSlots),
                        'old_count' => $currentCount,
                        'new_count' => $newCount
                    ]);
                }
            } finally {
                // Sempre liberar o lock
                $redis->del($lockKey);
            }

        } catch (\Exception $e) {
            Log::error('Error cleaning up orphaned slots', [
                'error' => $e->getMessage()
            ]);
        }
    }
}