<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Services\IntegrationSlotManager;
use App\Integracao\Application\Services\IntegrationProcessingService;
use App\Integracao\Application\Services\IntegrationLoggingService;
use App\Integracao\Application\Services\IntegrationStatusManager;

/**
 * Orquestrador principal do job de integração
 *
 * Responsabilidades:
 * - Coordenar todo o fluxo de processamento
 * - Garantir consistência de status no banco
 * - Gerenciar ciclo de vida do job
 * - Logging estruturado e rastreabilidade
 * - Auto-recuperação de falhas
 */
class IntegrationJobOrchestrator
{
    private IntegrationSlotManager $slotManager;
    private IntegrationProcessingService $processingService;
    private IntegrationLoggingService $loggingService;
    private IntegrationStatusManager $statusManager;
    private array $jobContext;

    public function __construct(
        IntegrationSlotManager $slotManager,
        IntegrationProcessingService $processingService,
        IntegrationLoggingService $loggingService,
        IntegrationStatusManager $statusManager
    ) {
        $this->slotManager = $slotManager;
        $this->processingService = $processingService;
        $this->loggingService = $loggingService;
        $this->statusManager = $statusManager;
    }

    /**
     * Executa o processamento completo da integração
     */
    public function execute(int $integrationId, array $jobContext): array
    {
        $this->jobContext = $jobContext;
        $startTime = microtime(true);

        Log::channel('integration')->info('🚀 ORCHESTRATOR: Processing started', [
            'integration_id' => $integrationId,
            'job_id' => $jobContext['job_id'] ?? null,
            'attempt' => $jobContext['attempt'] ?? 1
        ]);

        $slotAcquired = false;
        try {
            // 1. Tentar adquirir slot
            $slotResult = $this->slotManager->acquireSlot($integrationId);
            if (!$slotResult['acquired']) {
                return $this->handleSlotUnavailable($integrationId, $slotResult);
            }
            $slotAcquired = true;

            // 2. Carregar e validar dados
            $data = $this->loadIntegrationData($integrationId);
            if (!$data['success']) {
                return $data;
            }

            $integration = $data['integration'];
            $queue = $data['queue'];

            $queue = $this->resetQueueDataAndMarkAsProcessing($integration, $queue);

            $result = $this->executeProcessing($integration, $queue, $startTime);

            return $result;

        } catch (\Exception $e) {
            return $this->handleCriticalError($integrationId, $e, microtime(true) - $startTime);
        } finally {
            // CRÍTICO: Sempre liberar slot, mesmo em caso de exceção
            try {
                if ($slotAcquired) {
                    $this->slotManager->releaseSlot($integrationId);
                }
            } catch (\Exception $slotError) {
                Log::channel('integration')->error("💀 ORCHESTRATOR: CRITICAL - Failed to release slot in finally", [
                    'integration_id' => $integrationId,
                    'slot_error' => $slotError->getMessage()
                ]);
            }

            // CRÍTICO: Garantir que heartbeat seja sempre parado
            try {
                $heartbeat = app(\App\Integracao\Application\Services\IntegrationHeartbeat::class);
                $heartbeat->stopHeartbeat($integrationId, 'orchestrator_completed');
            } catch (\Exception $heartbeatError) {
                Log::channel('integration')->warning("⚠️ ORCHESTRATOR: Failed to stop heartbeat", [
                    'integration_id' => $integrationId,
                    'heartbeat_error' => $heartbeatError->getMessage()
                ]);
            }

            Log::channel('integration')->info('🏁 ORCHESTRATOR: Processing finished', [
                'integration_id' => $integrationId,
                'total_time' => microtime(true) - $startTime
            ]);
        }
    }

    /**
     * Carregar e validar dados da integração
     */
    private function loadIntegrationData(int $integrationId): array
    {
        try {
            $integration = Integracao::with(['user'])->find($integrationId);
            if (!$integration) {
                Log::channel('integration')->error("❌ ORCHESTRATOR: Integration not found", [
                    'integration_id' => $integrationId
                ]);
                return ['success' => false, 'reason' => 'integration_not_found'];
            }

            $queue = IntegrationsQueues::where('integration_id', $integrationId)->first();
            if (!$queue) {
                Log::channel('integration')->error("❌ ORCHESTRATOR: Queue not found", [
                    'integration_id' => $integrationId
                ]);
                return ['success' => false, 'reason' => 'queue_not_found'];
            }

            return [
                'success' => true,
                'integration' => $integration,
                'queue' => $queue
            ];

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ ORCHESTRATOR: Error loading data", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'reason' => 'data_load_error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Verificar se está realmente processando ou apenas preso
     */
    private function isReallyProcessing(Integracao $integration, IntegrationsQueues $queue): bool
    {
        if ($queue->status !== IntegrationsQueues::STATUS_IN_PROCESS) {
            return false;
        }

        if (!$queue->started_at) {
            Log::channel('integration')->warning("⚠️ ORCHESTRATOR: IN_PROCESS without started_at, will reset", [
                'integration_id' => $integration->id
            ]);
            return false;
        }

        $minutesProcessing = now()->diffInMinutes($queue->started_at);

        // Se passou mais de 2 horas, consideramos preso
        if ($minutesProcessing > 120) {
            Log::channel('integration')->warning("⚠️ ORCHESTRATOR: Integration stuck for {$minutesProcessing} minutes, will force reset", [
                'integration_id' => $integration->id,
                'minutes_stuck' => $minutesProcessing
            ]);
            return false;
        }

        // Verificar se há atividade recente nos logs (últimos 10 minutos)
        $hasRecentActivity = $this->hasRecentProcessingActivity($integration->id);

        if (!$hasRecentActivity && $minutesProcessing > 10) {
            Log::channel('integration')->warning("⚠️ ORCHESTRATOR: No recent activity for {$minutesProcessing} minutes, may be stuck", [
                'integration_id' => $integration->id,
                'minutes_processing' => $minutesProcessing
            ]);
            return false;
        }

        return true;
    }

    /**
     * Verificar atividade recente de processamento nos logs
     */
    private function hasRecentProcessingActivity(int $integrationId): bool
    {
        try {
            $integration = Integracao::select('id', 'user_id')->find($integrationId);
            if (!$integration) {
                return false;
            }

            $recentLogs = DB::table('anuncios as A')
                ->where('A.user_id', $integration->user_id)
                ->where('A.updated_at', '>=', now()->subMinutes(10))
                ->exists();

            return $recentLogs;

        } catch (\Exception $e) {
            Log::channel('integration')->warning("Failed to check recent activity", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
            return true;
        }
    }

    /**
     * Lidar com integração já processando
     */
    private function handleAlreadyProcessing(int $integrationId): array
    {
        Log::channel('integration')->info("⏳ ORCHESTRATOR: Integration already processing", [
            'integration_id' => $integrationId
        ]);

        return [
            'success' => false,
            'action' => 'retry_later',
            'reason' => 'already_processing',
            'delay_seconds' => 60
        ];
    }

    /**
     * Lidar com slot indisponível
     */
    private function handleSlotUnavailable(int $integrationId, array $slotResult): array
    {
        Log::channel('integration')->warning('⏳ ORCHESTRATOR: Slot unavailable', [
            'integration_id' => $integrationId,
            'reason' => $slotResult['reason']
        ]);

        return [
            'success' => false,
            'action' => 'retry_later',
            'reason' => 'slot_unavailable',
            'delay_seconds' => 60
        ];
    }

    /**
     * Resetar dados da queue e marcar como processando (ZERA TODOS OS CAMPOS)
     */
    private function resetQueueDataAndMarkAsProcessing(Integracao $integration, IntegrationsQueues $queue): IntegrationsQueues
    {
        $previousStatus = $queue->status;
        $previousAttempts = $queue->attempts ?? 0;
        $isRetry = $queue->status === IntegrationsQueues::STATUS_IN_PROCESS;

        // Usar o status manager para garantir reset completo e transacional
        $this->statusManager->markAsProcessing($integration, $queue);

        $queue->refresh();

        return $queue;
    }

    /**
     * Executar processamento principal
     */
    private function executeProcessing(Integracao $integration, IntegrationsQueues $queue, float $startTime): array
    {
        try {
            $result = $this->processingService->processIntegration($integration);
            $executionTime = microtime(true) - $startTime;

            if ($result['success']) {
                return $this->handleSuccess($integration, $queue, $result, $executionTime);
            } else {
                return $this->handleFailure($integration, $queue, $result, $executionTime);
            }

        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;
            return $this->handleProcessingException($integration, $queue, $e, $executionTime);
        }
    }

    /**
     * Lidar com processamento bem-sucedido
     */
    private function handleSuccess(Integracao $integration, IntegrationsQueues $queue, array $result, float $executionTime): array
    {
        $processedItems = $result['processed_items'] ?? 0;
        $totalItems = $result['total_items'] ?? 0;

        // CRÍTICO: Validar se realmente processou imóveis
        if ($processedItems === 0) {
            Log::channel('integration')->warning("⚠️ ORCHESTRATOR: No items processed - marking as error", [
                'integration_id' => $integration->id,
                'total_items' => $totalItems,
                'processed_items' => $processedItems,
                'execution_time' => $executionTime
            ]);

            return $this->handleFailure($integration, $queue, [
                'error' => 'No items were processed from XML',
                'error_type' => 'no_items_processed',
                'processed_items' => $processedItems,
                'total_items' => $totalItems
            ], $executionTime);
        }

        $this->statusManager->markAsCompleted($integration, $queue, $processedItems, $executionTime);

        // Atualizar dados específicos da integração
        $integration->update([
            'qtd' => $processedItems,
            'last_integration' => now()
        ]);

        return [
            'success' => true,
            'processed_items' => $processedItems,
            'total_items' => $totalItems,
            'execution_time' => $executionTime
        ];
    }

    /**
     * Lidar com falha no processamento
     */
    private function handleFailure(Integracao $integration, IntegrationsQueues $queue, array $result, float $executionTime): array
    {
        $attempts = $queue->attempts + 1;
        $isFinalFailure = $attempts >= 5;

        $errorDetails = [
            'error' => $result['error'] ?? 'Unknown error',
            'error_type' => $result['error_type'] ?? 'Unknown',
            'attempt' => $attempts,
            'is_final_failure' => $isFinalFailure,
            'execution_time' => $executionTime,
            'job_context' => $this->jobContext
        ];

        if ($isFinalFailure) {
            $this->statusManager->markAsFailed(
                $integration,
                $queue,
                $result['error'] ?? 'Processing failed after 5 attempts',
                $attempts,
                $executionTime,
                $errorDetails
            );
        } else {
            $this->statusManager->markAsError(
                $integration,
                $queue,
                $result['error'] ?? 'Processing failed',
                $attempts,
                $executionTime,
                $errorDetails
            );
        }

        Log::channel('integration')->error('❌ ORCHESTRATOR: Processing failed', [
            'integration_id' => $integration->id,
            'error' => $result['error'] ?? 'Unknown error',
            'attempts' => $attempts,
            'is_final_failure' => $isFinalFailure,
            'execution_time' => $executionTime
        ]);

        return [
            'success' => false,
            'error' => $result['error'] ?? 'Processing failed',
            'attempts' => $attempts,
            'is_final_failure' => $isFinalFailure,
            'action' => $isFinalFailure ? 'mark_failed' : 'retry_later'
        ];
    }

    /**
     * Lidar com exceção durante processamento
     */
    private function handleProcessingException(Integracao $integration, IntegrationsQueues $queue, \Exception $e, float $executionTime): array
    {
        $attempts = $queue->attempts + 1;
        $isFinalFailure = $attempts >= 5;

        $errorDetails = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'attempt' => $attempts,
            'is_final_failure' => $isFinalFailure,
            'execution_time' => $executionTime,
            'job_context' => $this->jobContext
        ];

        try {
            if ($isFinalFailure) {
                $this->statusManager->markAsFailed(
                    $integration,
                    $queue,
                    $e->getMessage(),
                    $attempts,
                    $executionTime,
                    $errorDetails
                );
            } else {
                $this->statusManager->markAsError(
                    $integration,
                    $queue,
                    $e->getMessage(),
                    $attempts,
                    $executionTime,
                    $errorDetails
                );
            }

            Log::channel('integration')->error("💥 ORCHESTRATOR: Processing exception handled", [
                'integration_id' => $integration->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'attempts' => $attempts,
                'is_final_failure' => $isFinalFailure,
                'execution_time' => $executionTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'attempts' => $attempts,
                'is_final_failure' => $isFinalFailure,
                'action' => $isFinalFailure ? 'mark_failed' : 'retry_later'
            ];

        } catch (\Exception $statusError) {
            Log::channel('integration')->error("💀 ORCHESTRATOR: CRITICAL - Failed to update status after exception", [
                'integration_id' => $integration->id,
                'original_error' => $e->getMessage(),
                'status_error' => $statusError->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'critical_error' => $statusError->getMessage(),
                'action' => 'critical_failure'
            ];
        }
    }

    /**
     * Lidar com erro crítico
     */
    private function handleCriticalError(int $integrationId, \Exception $e, float $executionTime): array
    {
        Log::channel('integration')->error("💀 ORCHESTRATOR: CRITICAL ERROR", [
            'integration_id' => $integrationId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'execution_time' => $executionTime,
            'job_context' => $this->jobContext
        ]);

        return [
            'success' => false,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'action' => 'critical_failure'
        ];
    }
}