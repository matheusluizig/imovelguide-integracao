<?php

namespace App\Integracao\Application\Handlers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Services\IntegrationProcessingService;
use App\Integracao\Application\Services\IntegrationLoggingService;
use App\Integracao\Application\Services\IntegrationSlotManager;

/**
 * Handler principal para processamento de integração
 *
 * Responsabilidades:
 * - Coordenar o fluxo de integração
 * - Gerenciar transações de banco
 * - Controlar locks e slots
 * - Logging estruturado
 */
class IntegrationJobHandler
{
    private IntegrationSlotManager $slotManager;
    private IntegrationLoggingService $loggingService;
    private IntegrationProcessingService $processingService;

    public function __construct(
        IntegrationSlotManager $slotManager,
        IntegrationLoggingService $loggingService,
        IntegrationProcessingService $processingService
    ) {
        $this->slotManager = $slotManager;
        $this->loggingService = $loggingService;
        $this->processingService = $processingService;
    }

    /**
     * Processar integração com controle completo de transações
     */
    public function handle(int $integrationId, int $attempt = 1, ?string $jobId = null): array
    {
        $startTime = microtime(true);
        $correlationId = null;

        Log::channel('integration')->info("🚀 Integration processing started", [
            'integration_id' => $integrationId,
            'attempt' => $attempt,
            'job_id' => $jobId,
            'timestamp' => now()->format('Y-m-d H:i:s')
        ]);

        try {
            // 1. Carregar dados da integração
            $result = $this->loadIntegrationData($integrationId);
            if (!$result['success']) {
                return $result;
            }

            $integration = $result['integration'];
            $queue = $result['queue'];

            // 2. Verificar se já está processando
            if ($queue->status === IntegrationsQueues::STATUS_IN_PROCESS) {
                return $this->handleProcessingIntegration($integrationId, $queue, $integration);
            }

            // 3. Tentar adquirir slot
            $slotResult = $this->slotManager->acquireSlot($integrationId);
            if (!$slotResult['acquired']) {
                return $this->handleSlotUnavailable($integrationId, $slotResult);
            }

            // 4. Inicializar logging
            $correlationId = $this->loggingService->logIntegrationStart($integration, [
                'job_id' => $jobId,
                'attempt' => $attempt
            ]);

            // 5. Adquirir lock de processamento
            $cacheKey = "integration_processing_{$integrationId}";
            $lock = Cache::lock($cacheKey, 21600); // 6 horas

            if (!$lock->get()) {
                $this->slotManager->releaseSlot($integrationId);
                return [
                    'success' => false,
                    'action' => 'retry_later',
                    'reason' => 'cache_lock_unavailable'
                ];
            }

            try {
                // 6. Executar processamento principal
                return $this->executeMainProcessing($integration, $queue, $correlationId, $startTime, $lock);

            } finally {
                $lock->release();
            }

        } catch (\Exception $e) {
            return $this->handleProcessingError($e, $integrationId, $correlationId, $startTime);
        } finally {
            $this->slotManager->releaseSlot($integrationId);

            Log::channel('integration')->info("🏁 Integration processing completed", [
                'integration_id' => $integrationId,
                'execution_time' => microtime(true) - $startTime,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Carregar e validar dados da integração
     */
    private function loadIntegrationData(int $integrationId): array
    {
        $integration = Integracao::with(['user'])->find($integrationId);
        if (!$integration) {
            Log::channel('integration')->error("❌ Integration not found", [
                'integration_id' => $integrationId
            ]);
            return ['success' => false, 'reason' => 'integration_not_found'];
        }

        $queue = IntegrationsQueues::where('integration_id', $integrationId)->first();
        if (!$queue) {
            Log::channel('integration')->error("❌ Queue not found for integration", [
                'integration_id' => $integrationId
            ]);
            return ['success' => false, 'reason' => 'queue_not_found'];
        }

        Log::channel('integration')->info("✅ Integration data loaded", [
            'integration_id' => $integrationId,
            'user_id' => $integration->user_id,
            'queue_status' => $queue->status,
            'integration_status' => $integration->status
        ]);

        return [
            'success' => true,
            'integration' => $integration,
            'queue' => $queue
        ];
    }

    /**
     * Lidar com integração já em processamento
     */
    private function handleProcessingIntegration(int $integrationId, IntegrationsQueues $queue, Integracao $integration): array
    {
        $minutesProcessing = $queue->started_at ? now()->diffInMinutes($queue->started_at) : 0;

        Log::channel('integration')->info("⏳ Integration already processing", [
            'integration_id' => $integrationId,
            'minutes_processing' => $minutesProcessing,
            'started_at' => $queue->started_at
        ]);

        // Auto-reset se travado há muito tempo (> 2 horas)
        if ($minutesProcessing > 120) {
            Log::channel('integration')->warning("🔄 Auto-resetting stuck integration", [
                'integration_id' => $integrationId,
                'minutes_stuck' => $minutesProcessing
            ]);

            DB::transaction(function() use ($queue, $integration, $minutesProcessing) {
                $queue->update([
                    'status' => IntegrationsQueues::STATUS_PENDING,
                    'started_at' => null,
                    'updated_at' => now(),
                    'error_message' => "Auto-reset após {$minutesProcessing} minutos travado"
                ]);

                if (in_array($integration->status, [6, 7, 8])) {
                    $integration->update(['status' => 3]); // XML_STATUS_IN_ANALYSIS
                }
            });

            return ['success' => false, 'action' => 'retry_now', 'reason' => 'auto_reset_completed'];
        }

        return [
            'success' => false,
            'action' => 'retry_later',
            'reason' => 'already_processing',
            'minutes_processing' => $minutesProcessing
        ];
    }

    /**
     * Lidar com slot indisponível
     */
    private function handleSlotUnavailable(int $integrationId, array $slotResult): array
    {
        Log::channel('integration')->info("⏳ Integration slot not available", [
            'integration_id' => $integrationId,
            'reason' => $slotResult['reason'],
            'details' => $slotResult
        ]);

        return [
            'success' => false,
            'action' => 'retry_later',
            'reason' => 'slot_unavailable',
            'slot_reason' => $slotResult['reason']
        ];
    }

    /**
     * Executar processamento principal
     */
    private function executeMainProcessing(Integracao $integration, IntegrationsQueues $queue, string $correlationId, float $startTime, $lock): array
    {
        // Atualizar status para processando
        $this->updateIntegrationStatus($integration, $queue, IntegrationsQueues::STATUS_IN_PROCESS, Integracao::XML_STATUS_IN_UPDATE_BOTH);

        // Processar integração
        $result = $this->processingService->processIntegration($integration);
        $executionTime = microtime(true) - $startTime;

        if ($result['success']) {
            return $this->handleProcessingSuccess($integration, $queue, $result, $executionTime, $correlationId);
        } else {
            return $this->handleProcessingFailure($integration, $queue, $result, $executionTime, $correlationId);
        }
    }

    /**
     * Lidar com sucesso no processamento
     */
    private function handleProcessingSuccess(Integracao $integration, IntegrationsQueues $queue, array $result, float $executionTime, string $correlationId): array
    {
        Log::channel('integration')->info("✅ Processing successful", [
            'integration_id' => $integration->id,
            'processed_items' => $result['processed_items'] ?? 0,
            'execution_time' => $executionTime
        ]);

        DB::transaction(function() use ($integration, $queue, $result, $executionTime) {
            $this->updateIntegrationStatus($integration, $queue, IntegrationsQueues::STATUS_DONE, Integracao::XML_STATUS_INTEGRATED);

            $queue->update([
                'completed_at' => now(),
                'ended_at' => now(),
                'execution_time' => $executionTime
            ]);
        });

        $this->loggingService->logIntegrationSuccess($integration, $correlationId, $result['metrics'] ?? []);

        return [
            'success' => true,
            'processed_items' => $result['processed_items'] ?? 0,
            'total_items' => $result['total_items'] ?? 0,
            'execution_time' => $executionTime
        ];
    }

    /**
     * Lidar com falha no processamento
     */
    private function handleProcessingFailure(Integracao $integration, IntegrationsQueues $queue, array $result, float $executionTime, string $correlationId): array
    {
        $error = new \Exception($result['error'] ?? 'Unknown processing error');

        Log::channel('integration')->error("❌ Processing failed", [
            'integration_id' => $integration->id,
            'error' => $error->getMessage(),
            'execution_time' => $executionTime
        ]);

        DB::transaction(function() use ($integration, $queue, $error, $executionTime) {
            $this->updateIntegrationStatus($integration, $queue, IntegrationsQueues::STATUS_STOPPED, Integracao::XML_STATUS_CRM_ERRO);

            $queue->update([
                'ended_at' => now(),
                'execution_time' => $executionTime,
                'error_message' => $error->getMessage(),
                'last_error_step' => 'processing'
            ]);
        });

        $this->loggingService->logIntegrationError($integration, $correlationId, $error);

        return [
            'success' => false,
            'error' => $error->getMessage(),
            'execution_time' => $executionTime
        ];
    }

    /**
     * Lidar com erro geral de processamento
     */
    private function handleProcessingError(\Exception $e, int $integrationId, ?string $correlationId, float $startTime): array
    {
        $executionTime = microtime(true) - $startTime;

        Log::channel('integration')->error("💥 Integration processing error", [
            'integration_id' => $integrationId,
            'error' => $e->getMessage(),
            'execution_time' => $executionTime
        ]);

        return [
            'success' => false,
            'error' => $e->getMessage(),
            'execution_time' => $executionTime
        ];
    }

    /**
     * Atualizar status da integração com logging detalhado
     */
    private function updateIntegrationStatus(Integracao $integration, IntegrationsQueues $queue, int $queueStatus, int $integrationStatus): void
    {
        Log::channel('integration')->info("📝 Updating integration status", [
            'integration_id' => $integration->id,
            'queue_status' => "{$queue->status} → {$queueStatus}",
            'integration_status' => "{$integration->status} → {$integrationStatus}"
        ]);

        $queueUpdated = $queue->update([
            'status' => $queueStatus,
            'started_at' => $queueStatus === IntegrationsQueues::STATUS_IN_PROCESS ? now() : $queue->started_at,
            'updated_at' => now()
        ]);

        if ($integration->status !== $integrationStatus) {
            $integrationUpdated = $integration->update(['status' => $integrationStatus]);
        }

        Log::channel('integration')->info("✅ Status updated successfully", [
            'integration_id' => $integration->id,
            'new_queue_status' => $queueStatus,
            'new_integration_status' => $integrationStatus
        ]);
    }
}
