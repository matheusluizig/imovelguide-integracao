<?php

namespace App\Integracao\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Integracao\Application\Handlers\IntegrationJobHandler;
use App\Integracao\Domain\Entities\IntegrationsQueues;

/**
 * Job de processamento de integração REFATORADO
 *
 * Responsabilidades REDUZIDAS:
 * - Apenas orquestração do job Laravel
 * - Retry logic e error handling
 * - Logging básico de job lifecycle
 *
 * TODA lógica de negócio foi movida para IntegrationJobHandler
 */
class ProcessIntegrationJobRefactored implements ShouldQueue
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
     * Executar o job (SIMPLIFICADO)
     */
    public function handle(IntegrationJobHandler $handler): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $result = $handler->handle(
            $this->integrationId,
            $this->attempts(),
            $this->job?->getJobId()
        );

        // Decidir ação baseada no resultado
        match ($result['action'] ?? 'complete') {
            'retry_now' => $this->retryNow(),
            'retry_later' => $this->retryLater(),
            'complete' => $this->complete($result),
            default => $this->complete($result)
        };
    }

    /**
     * Job falhou permanentemente
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('integration')->error("💀 Job failed permanently", [
            'integration_id' => $this->integrationId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage()
        ]);

        // Reset para permitir nova tentativa manual
        $this->resetIntegrationForRetry($exception);
    }

    /**
     * Tentar novamente imediatamente
     */
    private function retryNow(): void
    {
        $this->delete();
        self::dispatch($this->integrationId, $this->job?->getQueue())
            ->delay(now()->addSeconds(5));
    }

    /**
     * Tentar novamente com delay
     */
    private function retryLater(): void
    {
        $this->delete();
        self::dispatch($this->integrationId, $this->job?->getQueue())
            ->delay(now()->addMinutes(1));
    }

    /**
     * Completar job
     */
    private function complete(array $result): void
    {
        if (!$result['success']) {
            Log::channel('integration')->warning("⚠️ Job completed with failure", [
                'integration_id' => $this->integrationId,
                'reason' => $result['reason'] ?? 'unknown'
            ]);
        }
    }

    /**
     * Reset integração para nova tentativa
     */
    private function resetIntegrationForRetry(\Throwable $exception): void
    {
        try {
            $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();
            if ($queue) {
                $queue->update([
                    'status' => IntegrationsQueues::STATUS_PENDING,
                    'started_at' => null,
                    'error_message' => "Job failed after {$this->attempts()} attempts: {$exception->getMessage()}"
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('integration')->error("❌ Failed to reset integration", [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage()
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