<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Carbon\Carbon;

class IntegrationLoggingService
{
    private const LOG_CHANNEL = 'integration';
    private const CONTEXT_CACHE_TTL = 3600; // 1 hora
    private const METRICS_CACHE_TTL = 300; // 5 minutos

    public function logIntegrationStart(Integracao $integration, array $context = []): string
    {
        $correlationId = $this->generateCorrelationId();
        
        $logData = [
            'correlation_id' => $correlationId,
            'event' => 'integration_started',
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'system' => $integration->system ?? 'unknown',
            'url' => $integration->link,
            'status' => $integration->status,
            'context' => $context,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        Log::channel(self::LOG_CHANNEL)->info('🚀 Integration started', $logData);
        
        // Cache do contexto para correlação
        $this->cacheLogContext($correlationId, $logData);
        
        return $correlationId;
    }

    public function logIntegrationProgress(Integracao $integration, string $correlationId, array $progress): void
    {
        $logData = [
            'correlation_id' => $correlationId,
            'event' => 'integration_progress',
            'integration_id' => $integration->id,
            'progress' => $progress,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true)
        ];

        Log::channel(self::LOG_CHANNEL)->info('📊 Integration progress', $logData);
    }

    public function logIntegrationSuccess(Integracao $integration, string $correlationId, array $metrics): void
    {
        $executionTime = $this->getExecutionTime($correlationId);
        
        $logData = [
            'correlation_id' => $correlationId,
            'event' => 'integration_completed',
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'system' => $integration->system ?? 'unknown',
            'status' => 'success',
            'metrics' => $metrics,
            'execution_time' => $executionTime,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];

        Log::channel(self::LOG_CHANNEL)->info('✅ Integration completed successfully', $logData);
        
        // Atualizar métricas de performance
        $this->updatePerformanceMetrics($integration, $metrics, $executionTime);
        
        // Limpar cache do contexto
        $this->clearLogContext($correlationId);
    }

    public function logIntegrationError(Integracao $integration, string $correlationId, \Throwable $exception, array $context = []): void
    {
        $executionTime = $this->getExecutionTime($correlationId);
        
        $logData = [
            'correlation_id' => $correlationId,
            'event' => 'integration_failed',
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'system' => $integration->system ?? 'unknown',
            'status' => 'error',
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ],
            'context' => $context,
            'execution_time' => $executionTime,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true)
        ];

        Log::channel(self::LOG_CHANNEL)->error('❌ Integration failed', $logData);
        
        // Log crítico para Discord se necessário
        $this->logCriticalError($integration, $exception, $logData);
        
        // Atualizar métricas de erro
        $this->updateErrorMetrics($integration, $exception);
        
        // Limpar cache do contexto
        $this->clearLogContext($correlationId);
    }

    public function logXmlProcessing(Integracao $integration, string $correlationId, array $xmlMetrics): void
    {
        $logData = [
            'correlation_id' => $correlationId,
            'event' => 'xml_processing',
            'integration_id' => $integration->id,
            'xml_metrics' => $xmlMetrics,
            'timestamp' => now()->toISOString()
        ];

        Log::channel(self::LOG_CHANNEL)->info('📄 XML processing completed', $logData);
    }

    public function logImageProcessing(Integracao $integration, string $correlationId, array $imageMetrics): void
    {
        $logData = [
            'correlation_id' => $correlationId,
            'event' => 'image_processing',
            'integration_id' => $integration->id,
            'image_metrics' => $imageMetrics,
            'timestamp' => now()->toISOString()
        ];

        Log::channel(self::LOG_CHANNEL)->info('🖼️ Image processing completed', $logData);
    }

    public function logQueueOperation(string $operation, array $context = []): void
    {
        $logData = [
            'event' => 'queue_operation',
            'operation' => $operation,
            'context' => $context,
            'timestamp' => now()->toISOString()
        ];

        Log::channel(self::LOG_CHANNEL)->info("🔄 Queue operation: {$operation}", $logData);
    }

    public function logPerformanceMetrics(array $metrics): void
    {
        $logData = [
            'event' => 'performance_metrics',
            'metrics' => $metrics,
            'timestamp' => now()->toISOString()
        ];

        Log::channel(self::LOG_CHANNEL)->info('📈 Performance metrics', $logData);
    }

    public function getLogsByCorrelationId(string $correlationId): array
    {
        // Em produção, isso seria implementado com um sistema de busca de logs
        // Por enquanto, retorna dados do cache
        return Cache::get("log_context_{$correlationId}", []);
    }

    public function getIntegrationLogs(int $integrationId, int $limit = 100): array
    {
        // Em produção, isso seria implementado com um sistema de busca de logs
        // Por enquanto, retorna métricas do cache
        return Cache::get("integration_logs_{$integrationId}", []);
    }

    public function getSystemMetrics(): array
    {
        $cacheKey = 'integration_system_metrics';
        $metrics = Cache::get($cacheKey, []);
        
        if (empty($metrics)) {
            $metrics = $this->calculateSystemMetrics();
            Cache::put($cacheKey, $metrics, self::METRICS_CACHE_TTL);
        }
        
        return $metrics;
    }

    private function generateCorrelationId(): string
    {
        return 'int_' . uniqid() . '_' . time();
    }

    private function cacheLogContext(string $correlationId, array $context): void
    {
        Cache::put("log_context_{$correlationId}", $context, self::CONTEXT_CACHE_TTL);
    }

    private function clearLogContext(string $correlationId): void
    {
        Cache::forget("log_context_{$correlationId}");
    }

    private function getExecutionTime(string $correlationId): ?float
    {
        $context = Cache::get("log_context_{$correlationId}");
        if ($context && isset($context['timestamp'])) {
            $startTime = Carbon::parse($context['timestamp']);
            return $startTime->diffInSeconds(now(), true);
        }
        return null;
    }

    private function updatePerformanceMetrics(Integracao $integration, array $metrics, ?float $executionTime): void
    {
        $cacheKey = "integration_metrics_{$integration->id}";
        $existingMetrics = Cache::get($cacheKey, []);
        
        $newMetrics = array_merge($existingMetrics, [
            'last_execution_time' => $executionTime,
            'last_processed_items' => $metrics['processed_items'] ?? 0,
            'last_total_items' => $metrics['total_items'] ?? 0,
            'last_success_rate' => $metrics['success_rate'] ?? 0,
            'last_run' => now()->toISOString()
        ]);
        
        Cache::put($cacheKey, $newMetrics, self::METRICS_CACHE_TTL);
    }

    private function updateErrorMetrics(Integracao $integration, \Throwable $exception): void
    {
        $cacheKey = "integration_errors_{$integration->id}";
        $existingErrors = Cache::get($cacheKey, []);
        
        $errorData = [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
            'timestamp' => now()->toISOString()
        ];
        
        $existingErrors[] = $errorData;
        
        // Manter apenas os últimos 10 erros
        if (count($existingErrors) > 10) {
            $existingErrors = array_slice($existingErrors, -10);
        }
        
        Cache::put($cacheKey, $existingErrors, self::METRICS_CACHE_TTL);
    }

    private function logCriticalError(Integracao $integration, \Throwable $exception, array $logData): void
    {
        // Verificar se é um erro crítico que precisa de alerta
        $criticalPatterns = [
            'Falha ao inserir anúncio',
            'Falha ao atualizar anúncio',
            'Falha ao processar XML',
            'Limite de anúncios excedido',
            'Usuário sem plano ativo',
            'Erro de autenticação no XML',
            'Connection timeout',
            'Memory limit exceeded'
        ];

        foreach ($criticalPatterns as $pattern) {
            if (strpos($exception->getMessage(), $pattern) !== false) {
                Log::channel('slack')->critical('🚨 Critical Integration Error', [
                    'integration_id' => $integration->id,
                    'user_id' => $integration->user_id,
                    'system' => $integration->system ?? 'unknown',
                    'error' => $exception->getMessage(),
                    'correlation_id' => $logData['correlation_id'] ?? 'unknown'
                ]);
                break;
            }
        }
    }

    private function calculateSystemMetrics(): array
    {
        $today = now()->startOfDay();
        
        return [
            'total_integrations' => Integracao::count(),
            'active_integrations' => Integracao::where('status', Integracao::XML_STATUS_INTEGRATED)->count(),
            'processing_integrations' => Integracao::whereIn('status', [
                Integracao::XML_STATUS_IN_UPDATE_BOTH,
                Integracao::XML_STATUS_IN_DATA_UPDATE,
                Integracao::XML_STATUS_IN_IMAGE_UPDATE
            ])->count(),
            'completed_today' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
                ->where('completed_at', '>=', $today)->count(),
            'errors_today' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_ERROR)
                ->where('updated_at', '>=', $today)->count(),
            'avg_execution_time' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
                ->where('completed_at', '>=', $today)
                ->whereNotNull('execution_time')
                ->avg('execution_time'),
            'pending_jobs' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_PENDING)->count(),
            'processing_jobs' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count()
        ];
    }
}
