<?php

namespace App\Integracao\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Integracao\Application\Services\IntegrationJobOrchestrator;
use App\Integracao\Domain\Entities\IntegrationsQueues;

/**
 * Job de processamento de integração REFATORADO
 *
 * Responsabilidades MÍNIMAS:
 * - Configuração do job Laravel
 * - Orquestração via IntegrationJobOrchestrator
 * - Retry logic baseada em resultados
 * - Logging de ciclo de vida do job
 * - Limpeza garantida de recursos
 */
class ProcessIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $integrationId;
    public int $timeout = 86400;
    public int $tries = 5;
    public array $backoff = [60, 300, 900, 3600, 7200];

    public function __construct(int $integrationId, ?string $queueName = null)
    {
        $this->integrationId = $integrationId;
        $this->onConnection('redis');
        $this->onQueue($queueName ?? $this->determineQueueName($integrationId));
    }

    /**
     * Executar o job (LIMPO)
     */
    public function handle(IntegrationJobOrchestrator $orchestrator): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $jobContext = [
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'queue' => $this->job?->getQueue(),
            'memory_start' => memory_get_usage(true)
        ];

        Log::channel('integration')->info('🚀 JOB: ProcessIntegrationJob started', [
            'integration_id' => $this->integrationId,
            'context' => $jobContext
        ]);

        try {
            $result = $orchestrator->execute($this->integrationId, $jobContext);
            $this->handleResult($result);

        } catch (\Exception $e) {
            Log::channel('integration')->error('💥 JOB: Unhandled exception', [
                'integration_id' => $this->integrationId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'context' => $jobContext
            ]);

            $this->updateStatusOnFailure($e, $jobContext);
            throw $e;
        } finally {
            Log::channel('integration')->info('🏁 JOB: ProcessIntegrationJob finished', [
                'integration_id' => $this->integrationId,
                'memory_peak' => memory_get_peak_usage(true)
            ]);
        }
    }

    /**
     * Lidar com resultado do orquestrador
     */
    private function handleResult(array $result): void
    {
        switch ($result['action'] ?? 'complete') {
            case 'retry_later':
                $this->retryLater($result['delay_seconds'] ?? 60);
                break;
            case 'retry_now':
                $this->retryNow();
                break;
            case 'mark_failed':
                Log::channel('integration')->error('💥 JOB: Integration marked as failed after max attempts', [
                    'integration_id' => $this->integrationId,
                    'attempts' => $result['attempts'] ?? 0
                ]);
                break;
            case 'critical_failure':
                Log::channel('integration')->error('💀 JOB: Critical failure detected', [
                    'integration_id' => $this->integrationId,
                    'error' => $result['error'] ?? 'Unknown'
                ]);
                $this->fail(new \Exception($result['error'] ?? 'Critical failure'));
                break;
            default:
                if (!$result['success']) {
                    Log::channel('integration')->error('💥 JOB: Completed without processing items', [
                        'integration_id' => $this->integrationId,
                        'reason' => $result['reason'] ?? 'unknown'
                    ]);
                }
        }
    }

    /**
     * Job falhou permanentemente
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('integration')->error("💀 JOB: Failed permanently", [
            'integration_id' => $this->integrationId,
            'attempts' => $this->attempts(),
            'exception' => get_class($exception),
            'message' => $exception->getMessage()
        ]);

        // Garantir reset para STATUS_ERROR
        $this->resetToError($exception);
    }

    /**
     * Tentar novamente com delay
     */
    private function retryLater(int $delaySeconds): void
    {
        $this->delete();
        self::dispatch($this->integrationId, $this->job?->getQueue())
            ->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * Tentar novamente imediatamente
     */
    private function retryNow(): void
    {
        $this->delete();
        self::dispatch($this->integrationId, $this->job?->getQueue());
    }

    /**
     * Atualizar status no banco quando job falha (CRÍTICO para evitar integrações fantasma)
     */
    private function updateStatusOnFailure(\Throwable $exception, array $jobContext): void
    {
        try {
            $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();
            $integration = \App\Integracao\Domain\Entities\Integracao::find($this->integrationId);

            if ($queue && $integration) {
                $attempts = $this->attempts();
                $isFinalFailure = $attempts >= $this->tries;

                DB::transaction(function() use ($queue, $integration, $exception, $attempts, $isFinalFailure, $jobContext) {
                    if ($isFinalFailure) {
                        // Falha permanente - marcar como ERROR
                        $queue->update([
                            'status' => IntegrationsQueues::STATUS_ERROR,
                            'ended_at' => now(),
                            'attempts' => $attempts,
                            'error_message' => "Job failed permanently: {$exception->getMessage()}",
                            'last_error_step' => 'job_failed',
                            'error_details' => json_encode([
                                'exception' => get_class($exception),
                                'message' => $exception->getMessage(),
                                'file' => $exception->getFile(),
                                'line' => $exception->getLine(),
                                'final_attempt' => $attempts,
                                'job_context' => $jobContext
                            ], JSON_UNESCAPED_UNICODE),
                            'updated_at' => now()
                        ]);

                        $integration->update([
                            'status' => Integracao::XML_STATUS_CRM_ERRO,
                            'updated_at' => now()
                        ]);
                    } else {
                        // Falha temporária - marcar como STOPPED para retry
                        $queue->update([
                            'status' => IntegrationsQueues::STATUS_STOPPED,
                            'ended_at' => now(),
                            'attempts' => $attempts,
                            'error_message' => "Job failed (retry {$attempts}/{$this->tries}): {$exception->getMessage()}",
                            'last_error_step' => 'job_retry',
                            'error_details' => json_encode([
                                'exception' => get_class($exception),
                                'message' => $exception->getMessage(),
                                'file' => $exception->getFile(),
                                'line' => $exception->getLine(),
                                'attempt' => $attempts,
                                'max_attempts' => $this->tries,
                                'job_context' => $jobContext
                            ], JSON_UNESCAPED_UNICODE),
                            'updated_at' => now()
                        ]);

                        $integration->update([
                            'status' => Integracao::XML_STATUS_IN_ANALYSIS,
                            'updated_at' => now()
                        ]);
                    }
                });

                Log::channel('integration')->info("✅ JOB: Status updated on failure", [
                    'integration_id' => $this->integrationId,
                    'attempts' => $attempts,
                    'is_final_failure' => $isFinalFailure,
                    'status' => $isFinalFailure ? 'ERROR' : 'STOPPED'
                ]);
            }

        } catch (\Exception $updateError) {
            Log::channel('integration')->error("💀 JOB: CRITICAL - Failed to update status on failure", [
                'integration_id' => $this->integrationId,
                'original_error' => $exception->getMessage(),
                'update_error' => $updateError->getMessage()
            ]);
        }
    }

    /**
     * Reset para erro final (mantido para compatibilidade)
     */
    private function resetToError(\Throwable $exception): void
    {
        try {
            $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();
            $integration = \App\Integracao\Domain\Entities\Integracao::find($this->integrationId);

            if ($queue && $integration) {
                DB::transaction(function() use ($queue, $integration, $exception) {
                    $queue->update([
                        'status' => IntegrationsQueues::STATUS_ERROR,
                        'ended_at' => now(),
                        'attempts' => $this->attempts(),
                        'error_message' => "Job failed permanently: {$exception->getMessage()}",
                        'last_error_step' => 'job_failed',
                        'error_details' => json_encode([
                            'exception' => get_class($exception),
                            'message' => $exception->getMessage(),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                            'final_attempt' => $this->attempts()
                        ]),
                        'updated_at' => now()
                    ]);

                    $integration->update([
                        'status' => Integracao::XML_STATUS_CRM_ERRO,
                        'updated_at' => now()
                    ]);
                });

                Log::channel('integration')->info("✅ JOB: Reset to error status completed", [
                    'integration_id' => $this->integrationId
                ]);
            }

        } catch (\Exception $resetError) {
            Log::channel('integration')->error("💀 JOB: CRITICAL - Failed to reset to error status", [
                'integration_id' => $this->integrationId,
                'original_error' => $exception->getMessage(),
                'reset_error' => $resetError->getMessage()
            ]);
        }
    }

    /**
     * Determinar nome da fila baseado na prioridade
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
            Log::channel('integration')->warning("Failed to determine queue name", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }

        return 'normal-integrations';
    }
}