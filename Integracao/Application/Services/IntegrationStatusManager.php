<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;

/**
 * Gerenciador de status de integração
 *
 * Responsabilidades:
 * - Transições de status seguras e atômicas
 * - Validação de regras de negócio
 * - Logging detalhado de mudanças
 * - Prevenção de estados inconsistentes
 */
class IntegrationStatusManager
{
    /**
     * Atualizar status com validações, logging e locks atômicos
     */
    public function updateStatus(
        Integracao $integration,
        IntegrationsQueues $queue,
        int $queueStatus,
        int $integrationStatus,
        array $additionalData = []
    ): bool {
        return $this->updateStatusAtomic($integration, $queue, $queueStatus, $integrationStatus, $additionalData);
    }

    /**
     * Atualização atômica de status com locks para evitar race conditions
     */
    public function updateStatusAtomic(
        Integracao $integration,
        IntegrationsQueues $queue,
        int $queueStatus,
        int $integrationStatus,
        array $additionalData = [],
        callable $businessLogic = null
    ): bool {
        try {
            Log::channel('integration')->info("📝 STATUS: Starting atomic status update", [
                'integration_id' => $integration->id,
                'queue_status' => "{$queue->status} → {$queueStatus}",
                'integration_status' => "{$integration->status} → {$integrationStatus}"
            ]);

            return DB::transaction(function() use ($integration, $queue, $queueStatus, $integrationStatus, $additionalData, $businessLogic) {
                // 1. Adquirir locks PESSIMISTAS com SELECT FOR UPDATE NOWAIT para evitar deadlocks
                $currentQueue = IntegrationsQueues::where('id', $queue->id)
                    ->lockForUpdate()
                    ->first();
                $currentIntegration = Integracao::where('id', $integration->id)
                    ->lockForUpdate()
                    ->first();

                if (!$currentQueue || !$currentIntegration) {
                    throw new \Exception("Registro não encontrado durante lock");
                }

                // 2. VALIDAÇÃO CRÍTICA: Verificar se a transição ainda é válida APÓS o lock
                $isValidTransition = $this->isValidStatusTransition($currentQueue->status, $queueStatus);
                if (!$isValidTransition) {
                    // Log warning mas não falha - permite override em casos especiais
                    Log::channel('integration')->warning("⚠️ STATUS: Invalid queue status transition detected", [
                        'integration_id' => $integration->id,
                        'current_status' => $currentQueue->status,
                        'target_status' => $queueStatus,
                        'allowing_override' => true
                    ]);
                }

                // 3. Executar lógica de negócio customizada se fornecida
                if ($businessLogic) {
                    $businessLogic($currentIntegration, $currentQueue);
                }

                // 4. Atualizar dados da queue com validação de timestamp
                $queueData = array_merge([
                    'status' => $queueStatus,
                    'updated_at' => now()
                ], $additionalData);

                // Definir started_at e ZERAR TODOS OS CAMPOS quando marca como IN_PROCESS
                if ($queueStatus === IntegrationsQueues::STATUS_IN_PROCESS) {
                    $queueData = array_merge($queueData, [
                        'started_at' => now(),
                        'ended_at' => null,
                        'completed_at' => null,
                        'execution_time' => null,
                        'error_message' => null,
                        'last_error_step' => null,
                        'error_details' => null,
                        'attempts' => ($currentQueue->attempts ?? 0) + 1, // Incrementar tentativas
                    ]);
                }

                // 5. UPDATE com WHERE para garantir que não foi alterado por outra transação
                $updatedRows = $currentQueue->where('id', $currentQueue->id)
                    ->where('updated_at', $currentQueue->updated_at)
                    ->update($queueData);

                if ($updatedRows === 0) {
                    throw new \Exception("Queue was modified by another transaction during update");
                }

                // 6. Atualizar status da integração se necessário
                if ($currentIntegration->status !== $integrationStatus) {
                    $integrationUpdatedRows = $currentIntegration->where('id', $currentIntegration->id)
                        ->where('updated_at', $currentIntegration->updated_at)
                        ->update([
                            'status' => $integrationStatus,
                            'updated_at' => now()
                        ]);

                    if ($integrationUpdatedRows === 0) {
                        Log::channel('integration')->warning("⚠️ STATUS: Integration was modified by another transaction", [
                            'integration_id' => $integration->id
                        ]);
                    }
                }

                Log::channel('integration')->info("✅ STATUS: Atomic update completed successfully", [
                    'integration_id' => $integration->id,
                    'new_queue_status' => $queueStatus,
                    'new_integration_status' => $integrationStatus,
                    'previous_queue_status' => $currentQueue->status,
                    'previous_integration_status' => $currentIntegration->status
                ]);

                return true;
            });

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ STATUS: Atomic update failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'target_queue_status' => $queueStatus,
                'target_integration_status' => $integrationStatus
            ]);
            throw $e;
        }
    }

    /**
     * Marcar como processando (zera todos os campos de execução anterior)
     */
    public function markAsProcessing(Integracao $integration, IntegrationsQueues $queue): bool
    {
        return $this->updateStatusAtomic(
            $integration,
            $queue,
            IntegrationsQueues::STATUS_IN_PROCESS,
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            [], // Dados adicionais serão definidos automaticamente no updateStatusAtomic
            null
        );
    }

    /**
     * Marcar como concluído
     */
    public function markAsCompleted(
        Integracao $integration,
        IntegrationsQueues $queue,
        int $processedItems,
        float $executionTime
    ): bool {
        // CRÍTICO: Validar se realmente processou itens
        if ($processedItems <= 0) {
            Log::channel('integration')->error("❌ STATUS: Attempted to mark as completed with 0 processed items", [
                'integration_id' => $integration->id,
                'processed_items' => $processedItems,
                'execution_time' => $executionTime
            ]);
            throw new \InvalidArgumentException("Cannot mark integration as completed with 0 processed items");
        }

        return $this->updateStatus(
            $integration,
            $queue,
            IntegrationsQueues::STATUS_DONE,
            Integracao::XML_STATUS_INTEGRATED,
            [
                'ended_at' => now(),
                'completed_at' => now(),
                'execution_time' => $executionTime,
                'error_message' => null,
                'last_error_step' => null,
                'error_details' => null
            ]
        );
    }

    /**
     * Marcar como com erro (temporário)
     */
    public function markAsError(
        Integracao $integration,
        IntegrationsQueues $queue,
        string $errorMessage,
        int $attempts,
        float $executionTime,
        array $errorDetails = []
    ): bool {
        return $this->updateStatus(
            $integration,
            $queue,
            IntegrationsQueues::STATUS_STOPPED,
            Integracao::XML_STATUS_IN_ANALYSIS,
            [
                'ended_at' => now(),
                'execution_time' => $executionTime,
                'attempts' => $attempts,
                'error_message' => $errorMessage,
                'last_error_step' => 'processing',
                'error_details' => json_encode($errorDetails, JSON_UNESCAPED_UNICODE)
            ]
        );
    }

    /**
     * Marcar como falha permanente
     */
    public function markAsFailed(
        Integracao $integration,
        IntegrationsQueues $queue,
        string $errorMessage,
        int $attempts,
        float $executionTime,
        array $errorDetails = []
    ): bool {
        return $this->updateStatus(
            $integration,
            $queue,
            IntegrationsQueues::STATUS_ERROR,
            Integracao::XML_STATUS_CRM_ERRO,
            [
                'ended_at' => now(),
                'execution_time' => $executionTime,
                'attempts' => $attempts,
                'error_message' => $errorMessage,
                'last_error_step' => 'final_failure',
                'error_details' => json_encode($errorDetails, JSON_UNESCAPED_UNICODE)
            ]
        );
    }

    /**
     * Reset para pending (para retry) - zera TODOS os campos de execução
     */
    public function resetToPending(IntegrationsQueues $queue, string $reason = 'Manual reset'): bool
    {
        try {
            $queue->update($this->getResetQueueData($reason));

            Log::channel('integration')->info("🔄 STATUS: Reset to pending", [
                'integration_id' => $queue->integration_id,
                'reason' => $reason
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ STATUS: Failed to reset to pending", [
                'integration_id' => $queue->integration_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Reset completo para novo processamento - zera TODOS os campos de execução
     */
    public function resetForNewProcessing(IntegrationsQueues $queue, string $reason = 'Reset for new processing'): bool
    {
        try {
            $resetData = $this->getResetQueueData($reason);
            // Manter tentativas existentes para não perder histórico
            $resetData['attempts'] = $queue->attempts ?? 0;

            $queue->update($resetData);

            Log::channel('integration')->info("🔄 STATUS: Full reset for new processing", [
                'integration_id' => $queue->integration_id,
                'reason' => $reason,
                'previous_attempts' => $queue->attempts ?? 0
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ STATUS: Failed to reset for new processing", [
                'integration_id' => $queue->integration_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obter dados de reset completo para queue
     */
    private function getResetQueueData(string $reason = ''): array
    {
        return [
            'status' => IntegrationsQueues::STATUS_PENDING,
            'started_at' => null,
            'ended_at' => null,
            'completed_at' => null,
            'execution_time' => null,
            'error_message' => $reason ?: null,
            'last_error_step' => null,
            'error_details' => null,
            'attempts' => 0, // Pode ser sobrescrito se necessário
            'updated_at' => now()
        ];
    }

    /**
     * Verificar se transição de status é válida (método helper)
     */
    private function isValidStatusTransition(int $currentStatus, int $targetStatus): bool
    {
        $validQueueTransitions = [
            IntegrationsQueues::STATUS_PENDING => [
                IntegrationsQueues::STATUS_IN_PROCESS,
                IntegrationsQueues::STATUS_STOPPED,
                IntegrationsQueues::STATUS_ERROR
            ],
            IntegrationsQueues::STATUS_IN_PROCESS => [
                IntegrationsQueues::STATUS_DONE,
                IntegrationsQueues::STATUS_STOPPED,
                IntegrationsQueues::STATUS_ERROR
            ],
            IntegrationsQueues::STATUS_STOPPED => [
                IntegrationsQueues::STATUS_PENDING,
                IntegrationsQueues::STATUS_ERROR
            ],
            IntegrationsQueues::STATUS_ERROR => [
                IntegrationsQueues::STATUS_PENDING
            ],
            IntegrationsQueues::STATUS_DONE => [
                IntegrationsQueues::STATUS_PENDING, // Para reprocessamento
                IntegrationsQueues::STATUS_IN_PROCESS // Para jobs simultâneos
            ]
        ];

        return isset($validQueueTransitions[$currentStatus]) &&
               in_array($targetStatus, $validQueueTransitions[$currentStatus]);
    }

    /**
     * Validar transição de status
     */
    private function validateStatusTransition(IntegrationsQueues $queue, int $targetQueueStatus, int $targetIntegrationStatus): void
    {
        // Validar transições válidas de queue
        $validQueueTransitions = [
            IntegrationsQueues::STATUS_PENDING => [
                IntegrationsQueues::STATUS_IN_PROCESS,
                IntegrationsQueues::STATUS_STOPPED,
                IntegrationsQueues::STATUS_ERROR
            ],
            IntegrationsQueues::STATUS_IN_PROCESS => [
                IntegrationsQueues::STATUS_DONE,
                IntegrationsQueues::STATUS_STOPPED,
                IntegrationsQueues::STATUS_ERROR
            ],
            IntegrationsQueues::STATUS_STOPPED => [
                IntegrationsQueues::STATUS_PENDING,
                IntegrationsQueues::STATUS_ERROR
            ],
            IntegrationsQueues::STATUS_ERROR => [
                IntegrationsQueues::STATUS_PENDING
            ],
            IntegrationsQueues::STATUS_DONE => [
                IntegrationsQueues::STATUS_PENDING, // Para reprocessamento
                IntegrationsQueues::STATUS_IN_PROCESS // Para jobs simultâneos
            ]
        ];

        $currentStatus = $queue->status;
        if (isset($validQueueTransitions[$currentStatus]) &&
            !in_array($targetQueueStatus, $validQueueTransitions[$currentStatus])) {

            Log::channel('integration')->warning("⚠️ STATUS: Invalid queue status transition", [
                'integration_id' => $queue->integration_id,
                'current_status' => $currentStatus,
                'target_status' => $targetQueueStatus,
                'valid_transitions' => $validQueueTransitions[$currentStatus]
            ]);
        }

        // Validar status de integração
        $validIntegrationStatuses = [
            Integracao::XML_STATUS_NOT_INTEGRATED,
            Integracao::XML_STATUS_INTEGRATED,
            Integracao::XML_STATUS_IN_ANALYSIS,
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE,
            Integracao::XML_STATUS_CRM_ERRO,
            Integracao::XML_STATUS_LINKS_NOT_WORKING,
            Integracao::XML_STATUS_WRONG_MODEL
        ];

        if (!in_array($targetIntegrationStatus, $validIntegrationStatuses)) {
            throw new \InvalidArgumentException("Invalid integration status: {$targetIntegrationStatus}");
        }
    }

    /**
     * Obter nome do status para logging
     */
    public function getStatusName(int $status, string $type = 'queue'): string
    {
        if ($type === 'queue') {
            return match($status) {
                IntegrationsQueues::STATUS_PENDING => 'PENDING',
                IntegrationsQueues::STATUS_IN_PROCESS => 'IN_PROCESS',
                IntegrationsQueues::STATUS_DONE => 'DONE',
                IntegrationsQueues::STATUS_STOPPED => 'STOPPED',
                IntegrationsQueues::STATUS_ERROR => 'ERROR',
                default => "UNKNOWN({$status})"
            };
        }

        return match($status) {
            Integracao::XML_STATUS_NOT_INTEGRATED => 'NOT_INTEGRATED',
            Integracao::XML_STATUS_INTEGRATED => 'INTEGRATED',
            Integracao::XML_STATUS_IN_ANALYSIS => 'IN_ANALYSIS',
            Integracao::XML_STATUS_IN_UPDATE_BOTH => 'IN_UPDATE_BOTH',
            Integracao::XML_STATUS_IN_DATA_UPDATE => 'IN_DATA_UPDATE',
            Integracao::XML_STATUS_IN_IMAGE_UPDATE => 'IN_IMAGE_UPDATE',
            Integracao::XML_STATUS_CRM_ERRO => 'CRM_ERROR',
            default => "UNKNOWN({$status})"
        };
    }
}