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
use Illuminate\Redis\Connections\PhpRedisConnection;
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

    private const ACTIVE_INTEGRATIONS_SET_KEY = 'imovelguide_database_active_integrations';
    private const ACTIVE_INTEGRATIONS_COUNT_KEY = 'imovelguide_database_active_integrations_count';
    private const ACTIVE_INTEGRATIONS_TTL = 3600;
    private const MAX_CONCURRENT_INTEGRATIONS = 3;

    private const ACQUIRE_SLOT_LUA = <<<'LUA'
local activeSetKey = KEYS[1]
local counterKey = KEYS[2]
local integrationId = ARGV[1]
local maxSlots = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])

if redis.call('SISMEMBER', activeSetKey, integrationId) == 1 then
    return 0
end

local currentCount = tonumber(redis.call('GET', counterKey) or '0')
if currentCount >= maxSlots then
    return 0
end

redis.call('SADD', activeSetKey, integrationId)
if ttl > 0 then
    redis.call('EXPIRE', activeSetKey, ttl)
end

local newCount = redis.call('INCR', counterKey)
if ttl > 0 then
    redis.call('EXPIRE', counterKey, ttl)
end

return newCount
LUA;

    public $integrationId;
    public $timeout = 86400;
    public $tries = 5;
    public $backoff = [60, 300, 900, 3600, 7200];

    private $slotStatus = 'unknown';
    private $slotErrorMessage = null;

    public function __construct(int $integrationId, ?string $queueName = null)
    {
        $this->integrationId = $integrationId;
        $this->onConnection('redis');
        $this->onQueue($queueName ?? 'normal-integrations');
    }

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
            Log::channel('integration')->warning('Failed to determine queue name, using default', [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }

        return 'normal-integrations';
    }

    public function handle()
    {
        ini_set('memory_limit', '2G');
        set_time_limit(0);
        $startTime = microtime(true);
        $integration = null;
        $queue = null;
        $correlationId = null;

        Log::channel('integration')->info("🚀 WORKER: ProcessIntegrationJob started", [
            'integration_id' => $this->integrationId,
            'attempt' => $this->attempts(),
            'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => now()->format('Y-m-d H:i:s')
        ]);

        Log::channel('integration')->info("🔒 WORKER: Attempting to acquire integration slot", [
            'integration_id' => $this->integrationId,
            'attempt' => $this->attempts(),
            'timestamp' => now()->format('Y-m-d H:i:s')
        ]);
        
        if (!$this->acquireIntegrationSlot()) {
            $queueName = $this->job ? $this->job->getQueue() : $this->determineQueueName($this->integrationId);

            if ($this->slotStatus === 'error') {
                Log::channel('integration')->error('❌ WORKER: CRITICAL: Failed to acquire integration slot due to error', [
                    'integration_id' => $this->integrationId,
                    'error' => $this->slotErrorMessage,
                    'attempt' => $this->attempts(),
                    'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);

                throw new \RuntimeException($this->slotErrorMessage ?? 'Unknown error acquiring integration slot');
            }

            $delaySeconds = 60;

            Log::channel('integration')->info('⏳ WORKER: Integration slot not available, re-dispatching job with delay', [
                'integration_id' => $this->integrationId,
                'attempt' => $this->attempts(),
                'queue' => $queueName,
                'delay_seconds' => $delaySeconds,
                'slot_status' => $this->slotStatus,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            $this->delete();
            self::dispatch($this->integrationId, $queueName)->delay(now()->addSeconds($delaySeconds));

            return;
        }

        try {
            Log::channel('integration')->info("📊 WORKER: Loading integration and queue data", [
                'integration_id' => $this->integrationId,
                'attempt' => $this->attempts(),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
            
            $integration = Integracao::with(['user'])->find($this->integrationId);
            if (!$integration) {
                Log::channel('integration')->error("❌ WORKER: CRITICAL: Integration not found in database", [
                    'integration_id' => $this->integrationId,
                    'attempt' => $this->attempts(),
                    'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);
                throw new \RuntimeException("Integration {$this->integrationId} not found");
            }

            Log::channel('integration')->info("✅ WORKER: Integration loaded successfully", [
                'integration_id' => $this->integrationId,
                'user_id' => $integration->user_id,
                'link' => $integration->link,
                'status' => $integration->status,
                'user_exists' => $integration->user !== null,
                'user_active' => $integration->user ? !$integration->user->inative : false,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();
            if (!$queue) {
                Log::channel('integration')->error("❌ WORKER: CRITICAL: Queue not found for integration", [
                    'integration_id' => $this->integrationId,
                    'attempt' => $this->attempts(),
                    'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);
                throw new \RuntimeException("Queue not found for integration {$this->integrationId}");
            }

            Log::channel('integration')->info("✅ WORKER: Queue loaded successfully", [
                'integration_id' => $this->integrationId,
                'queue_id' => $queue->id,
                'queue_status' => $queue->status,
                'queue_priority' => $queue->priority,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            if ($queue->status === IntegrationsQueues::STATUS_IN_PROCESS) {
                $delaySeconds = 60;
                $queueName = $this->job ? $this->job->getQueue() : $this->determineQueueName($this->integrationId);

                Log::channel('integration')->info("⏳ WORKER: Integration already processing, re-dispatching job with delay", [
                    'integration_id' => $this->integrationId,
                    'queue' => $queueName,
                    'delay_seconds' => $delaySeconds,
                    'attempt' => $this->attempts(),
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);

                $this->delete();
                self::dispatch($this->integrationId, $queueName)->delay(now()->addSeconds($delaySeconds));
                return;
            }

            Log::channel('integration')->info("🔄 WORKER: Starting integration processing", [
                'integration_id' => $this->integrationId,
                'url' => $integration->link,
                'user_id' => $integration->user_id,
                'attempt' => $this->attempts(),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            $loggingService = app(IntegrationLoggingService::class);
            $correlationId = $loggingService->logIntegrationStart($integration, [
                'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                'attempt' => $this->attempts(),
                'queue' => $this->job ? $this->job->getQueue() : 'unknown'
            ]);

            Log::channel('integration')->info("📝 WORKER: Integration logging started", [
                'integration_id' => $this->integrationId,
                'correlation_id' => $correlationId,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            $cacheKey = "integration_processing_{$this->integrationId}";
            $lock = null;

            Log::channel('integration')->info("🔐 WORKER: Acquiring cache lock", [
                'integration_id' => $this->integrationId,
                'cache_key' => $cacheKey,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            try {
                $lock = Cache::lock($cacheKey, 21600);

                if (!$lock->get()) {
                    $delaySeconds = 300;
                    $queueName = $this->job ? $this->job->getQueue() : $this->determineQueueName($this->integrationId);

                    Log::channel('integration')->info("⏳ WORKER: Integration already being processed, re-dispatching job with delay", [
                        'integration_id' => $this->integrationId,
                        'queue' => $queueName,
                        'delay_seconds' => $delaySeconds,
                        'attempt' => $this->attempts(),
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);

                    $this->delete();
                    self::dispatch($this->integrationId, $queueName)->delay(now()->addSeconds($delaySeconds));
                    return;
                }

                Log::channel('integration')->info("✅ WORKER: Cache lock acquired successfully", [
                    'integration_id' => $this->integrationId,
                    'cache_key' => $cacheKey,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);

                Log::channel('integration')->info("📊 WORKER: Updating integration status to IN_PROCESS", [
                    'integration_id' => $this->integrationId,
                    'queue_id' => $queue->id,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);

                $this->updateStatus($integration, $queue, IntegrationsQueues::STATUS_IN_PROCESS, Integracao::XML_STATUS_IN_UPDATE_BOTH);

                Log::channel('integration')->info("🔄 WORKER: Starting XML processing service", [
                    'integration_id' => $this->integrationId,
                    'url' => $integration->link,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);

                $integrationService = app(IntegrationProcessingService::class);
                $result = $integrationService->processIntegration($integration);

                $executionTime = microtime(true) - $startTime;

                Log::channel('integration')->info("📈 WORKER: Processing completed", [
                    'integration_id' => $this->integrationId,
                    'success' => $result['success'],
                    'execution_time' => $executionTime,
                    'processed_items' => $result['processed_items'] ?? 0,
                    'total_items' => $result['total_items'] ?? 0,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);

                if ($result['success']) {
                    Log::channel('integration')->info("✅ WORKER: Processing successful, updating status to DONE", [
                        'integration_id' => $this->integrationId,
                        'processed_items' => $result['processed_items'] ?? 0,
                        'total_items' => $result['total_items'] ?? 0,
                        'execution_time' => $executionTime,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);

                    DB::transaction(function() use ($integration, $queue, $result, $executionTime) {
                        Log::channel('integration')->info("🔄 WORKER: Executing success transaction", [
                            'integration_id' => $this->integrationId,
                            'queue_id' => $queue->id,
                            'current_queue_status' => $queue->status,
                            'current_integration_status' => $integration->status
                        ]);
                        
                        $this->updateStatus($integration, $queue, IntegrationsQueues::STATUS_DONE, Integracao::XML_STATUS_INTEGRATED);
                        $queue->update([
                            'completed_at' => now(),
                            'ended_at' => now(),
                            'execution_time' => $executionTime
                        ]);
                        
                        Log::channel('integration')->info("✅ WORKER: Success transaction completed", [
                            'integration_id' => $this->integrationId,
                            'new_queue_status' => IntegrationsQueues::STATUS_DONE,
                            'new_integration_status' => Integracao::XML_STATUS_INTEGRATED
                        ]);
                    });

                    Log::channel('integration')->info("🎉 WORKER: Integration completed successfully", [
                        'integration_id' => $this->integrationId,
                        'execution_time' => $executionTime,
                        'processed_items' => $result['processed_items'] ?? 0,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);

                    $loggingService->logIntegrationSuccess($integration, $correlationId, $result['metrics'] ?? []);
                    $loggingService->logPerformanceMetrics([
                        'integration_id' => $this->integrationId,
                        'execution_time' => $executionTime,
                        'processed_items' => $result['processed_items'] ?? 0,
                        'total_items' => $result['total_items'] ?? 0,
                        'success_rate' => $result['metrics']['success_rate'] ?? 0
                    ]);

                } else {
                    Log::channel('integration')->error("❌ WORKER: Processing failed", [
                        'integration_id' => $this->integrationId,
                        'error' => $result['error'] ?? 'Unknown processing error',
                        'execution_time' => $executionTime,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);
                    throw new \Exception($result['error'] ?? 'Unknown processing error');
                }
            } finally {
                if ($lock) {
                    Log::channel('integration')->info("🔓 WORKER: Releasing cache lock", [
                        'integration_id' => $this->integrationId,
                        'cache_key' => $cacheKey,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);
                    $lock->release();
                }
            }

        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;
            
            Log::channel('integration')->error("💥 WORKER: ProcessIntegrationJob FAILED", [
                'integration_id' => $this->integrationId,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'execution_time' => $executionTime,
                'attempt' => $this->attempts(),
                'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                'queue_name' => $this->job ? $this->job->getQueue() : 'unknown',
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'trace' => $e->getTraceAsString(),
                'integration_exists' => $integration !== null,
                'queue_exists' => $queue !== null,
                'correlation_id' => $correlationId,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
            
            if ($integration && $correlationId) {
                try {
                    Log::channel('integration')->info("📝 WORKER: Logging integration error", [
                        'integration_id' => $this->integrationId,
                        'correlation_id' => $correlationId,
                        'error_type' => get_class($e),
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);

                    $loggingService = app(IntegrationLoggingService::class);
                    $loggingService->logIntegrationError($integration, $correlationId, $e, [
                        'job_id' => $this->job ? $this->job->getJobId() : 'unknown',
                        'attempt' => $this->attempts(),
                        'execution_time' => $executionTime
                    ]);

                    Log::channel('integration')->info("✅ WORKER: Integration error logged successfully", [
                        'integration_id' => $this->integrationId,
                        'correlation_id' => $correlationId,
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);
                } catch (\Exception $logError) {
                    Log::channel('integration')->error("❌ WORKER: Failed to log integration error", [
                        'integration_id' => $this->integrationId,
                        'original_error' => $e->getMessage(),
                        'log_error' => $logError->getMessage(),
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);
                }
            }

            if ($integration && $queue) {
                try {
                    Log::channel('integration')->info("💾 WORKER: Updating database status to ERROR", [
                        'integration_id' => $this->integrationId,
                        'queue_id' => $queue->id,
                        'error_type' => get_class($e),
                        'timestamp' => now()->format('Y-m-d H:i:s')
                    ]);

                    DB::transaction(function() use ($integration, $queue, $e, $executionTime, $correlationId) {
                        Log::channel('integration')->info("🔄 WORKER: Executing error transaction", [
                            'integration_id' => $this->integrationId,
                            'queue_id' => $queue->id,
                            'current_queue_status' => $queue->status,
                            'current_integration_status' => $integration->status,
                            'error_message' => $e->getMessage()
                        ]);
                        
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
                                'correlation_id' => $correlationId,
                                'memory_usage' => memory_get_usage(true),
                                'memory_peak' => memory_get_peak_usage(true)
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        ]);
                        
                        Log::channel('integration')->info("❌ WORKER: Error transaction completed", [
                            'integration_id' => $this->integrationId,
                            'new_queue_status' => IntegrationsQueues::STATUS_STOPPED,
                            'new_integration_status' => Integracao::XML_STATUS_CRM_ERRO
                        ]);
                    });
                } catch (\Exception $dbError) {
                    Log::channel('integration')->error("Failed to update database after job error", [
                        'integration_id' => $this->integrationId,
                        'original_error' => $e->getMessage(),
                        'db_error' => $dbError->getMessage()
                    ]);
                }
            }

            throw $e;
        } finally {
            Log::channel('integration')->info("🔓 WORKER: Releasing integration slot", [
                'integration_id' => $this->integrationId,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);

            $this->releaseIntegrationSlot();

            if (isset($lock) && $lock) {
                Log::channel('integration')->info("🔓 WORKER: Releasing cache lock in finally", [
                    'integration_id' => $this->integrationId,
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]);
                $lock->release();
            }

            Log::channel('integration')->info("🏁 WORKER: ProcessIntegrationJob completed", [
                'integration_id' => $this->integrationId,
                'execution_time' => microtime(true) - $startTime,
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
        }
    }

    private function updateStatus(Integracao $integration, IntegrationsQueues $queue, int $queueStatus, int $integrationStatus): void
    {
        Log::channel('integration')->info("📝 WORKER: updateStatus started", [
            'integration_id' => $this->integrationId,
            'queue_current_status' => $queue->status,
            'queue_target_status' => $queueStatus,
            'integration_current_status' => $integration->status,
            'integration_target_status' => $integrationStatus
        ]);

        $queueUpdateData = [
            'status' => $queueStatus,
            'started_at' => $queueStatus === IntegrationsQueues::STATUS_IN_PROCESS ? now() : $queue->started_at,
            'updated_at' => now()
        ];
        
        $queueUpdated = $queue->update($queueUpdateData);
        
        Log::channel('integration')->info("📊 WORKER: Queue status updated", [
            'integration_id' => $this->integrationId,
            'queue_id' => $queue->id,
            'update_successful' => $queueUpdated,
            'new_status' => $queueStatus,
            'update_data' => $queueUpdateData
        ]);

        if ($integration->status !== $integrationStatus) {
            $integrationUpdated = $integration->update(['status' => $integrationStatus]);
            
            Log::channel('integration')->info("🏢 WORKER: Integration status updated", [
                'integration_id' => $this->integrationId,
                'update_successful' => $integrationUpdated,
                'old_status' => $integration->getOriginal('status'),
                'new_status' => $integrationStatus
            ]);
        } else {
            Log::channel('integration')->info("🏢 WORKER: Integration status unchanged", [
                'integration_id' => $this->integrationId,
                'current_status' => $integration->status,
                'target_status' => $integrationStatus
            ]);
        }
        
        Log::channel('integration')->info("✅ WORKER: updateStatus completed", [
            'integration_id' => $this->integrationId
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('integration')->error("💀 WORKER: Integration job failed permanently", [
            'integration_id' => $this->integrationId,
            'error' => $exception->getMessage(),
            'error_type' => get_class($exception),
            'attempts' => $this->attempts(),
            'timestamp' => now()->format('Y-m-d H:i:s')
        ]);

        $this->releaseIntegrationSlot();

        $integration = Integracao::find($this->integrationId);
        $queue = IntegrationsQueues::where('integration_id', $this->integrationId)->first();

        if ($integration && $queue) {
            if ($exception instanceof \Predis\Connection\ConnectionException ||
                $exception instanceof \RedisException ||
                strpos($exception->getMessage(), 'redis') !== false ||
                strpos($exception->getMessage(), 'connection') !== false) {
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

                dispatch(new self($this->integrationId))->delay(now()->addMinutes(30));

                Log::channel('integration')->info("Integration job rescheduled due to Redis connection issues", [
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

    private function acquireIntegrationSlot(): bool
    {
        try {
            $redis = app('redis')->connection();

            $scriptResult = $this->executeAcquireSlotScript($redis);
            if ($scriptResult > 0) {
                $this->slotStatus = 'acquired';
                Log::channel('integration')->debug('Integration slot acquired successfully', [
                    'integration_id' => $this->integrationId,
                    'current_count' => $scriptResult
                ]);
                return true;
            }

            $isActive = $redis->sismember(self::ACTIVE_INTEGRATIONS_SET_KEY, $this->integrationId);
            if ($isActive) {
                $this->slotStatus = 'already_active';
                Log::channel('integration')->info('Integration already active, slot not available', [
                    'integration_id' => $this->integrationId
                ]);
                return false;
            }

            $count = (int) ($redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY) ?: 0);
            if ($count >= self::MAX_CONCURRENT_INTEGRATIONS) {
                $this->cleanupOrphanedSlots($redis);

                $scriptResult = $this->executeAcquireSlotScript($redis);
                if ($scriptResult > 0) {
                    $this->slotStatus = 'acquired';
                    Log::channel('integration')->debug('Integration slot acquired successfully after cleanup', [
                        'integration_id' => $this->integrationId,
                        'current_count' => $scriptResult
                    ]);
                    return true;
                }

                $count = (int) ($redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY) ?: 0);
                $this->slotStatus = 'max_reached';
                Log::channel('integration')->info('Max concurrent integrations reached, slot not available', [
                    'integration_id' => $this->integrationId,
                    'current_count' => $count
                ]);
                return false;
            }

            $this->slotStatus = 'transaction_failed';
            Log::channel('integration')->info('Integration slot unavailable due to unexpected state', [
                'integration_id' => $this->integrationId,
                'current_count' => $count
            ]);
            return false;

        } catch (\Exception $e) {
            $this->slotStatus = 'error';
            $this->slotErrorMessage = $e->getMessage();
            Log::channel('integration')->error('Error acquiring integration slot', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function executeAcquireSlotScript($redis): int
    {
        $result = $this->evalLuaScript(
            $redis,
            self::ACQUIRE_SLOT_LUA,
            [
                self::ACTIVE_INTEGRATIONS_SET_KEY,
                self::ACTIVE_INTEGRATIONS_COUNT_KEY,
            ],
            [
                (string) $this->integrationId,
                self::MAX_CONCURRENT_INTEGRATIONS,
                self::ACTIVE_INTEGRATIONS_TTL,
            ]
        );

        return (int) $result;
    }

    private function evalLuaScript($redis, string $script, array $keys, array $arguments)
    {
        if ($redis instanceof PhpRedisConnection) {
            return $redis->eval($script, array_merge($keys, $arguments), count($keys));
        }

        return $redis->eval($script, count($keys), ...$keys, ...$arguments);
    }

    private function releaseIntegrationSlot(): void
    {
        try {
            $redis = app('redis')->connection();

            $wasActive = $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $this->integrationId);

            if ($wasActive) {
                $count = $redis->decr(self::ACTIVE_INTEGRATIONS_COUNT_KEY);
                if ($count < 0) {
                    $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, 0);
                }

                Log::channel('integration')->debug("Integration slot released successfully", [
                    'integration_id' => $this->integrationId,
                    'remaining_count' => max(0, $count)
                ]);
            } else {
                Log::channel('integration')->debug("Integration slot was not active", [
                    'integration_id' => $this->integrationId
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('integration')->error('Error releasing integration slot', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function cleanupOrphanedSlots($redis): void
    {
        try {
            $lockKey = "cleanup_orphaned_slots_lock";
            $lock = $redis->set($lockKey, 1, 'EX', 30, 'NX');

            if (!$lock) {
                Log::channel('integration')->debug("Cleanup already in progress, skipping");
                return;
            }

            try {
                $activeIntegrations = $redis->smembers(self::ACTIVE_INTEGRATIONS_SET_KEY);
                $currentCount = $redis->get(self::ACTIVE_INTEGRATIONS_COUNT_KEY) ?: 0;

                Log::channel('integration')->debug("Checking for orphaned slots", [
                    'active_integrations' => $activeIntegrations,
                    'current_count' => $currentCount
                ]);

                if (empty($activeIntegrations)) {
                    if ($currentCount > 0) {
                        $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, 0);
                        Log::channel('integration')->info("Reset integration count to 0 - no active integrations found");
                    }
                    return;
                }

                $orphanedSlots = [];
                foreach ($activeIntegrations as $integrationId) {
                    $queue = IntegrationsQueues::where('integration_id', $integrationId)
                        ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                        ->first();

                    if (!$queue) {
                        $orphanedSlots[] = $integrationId;
                    }
                }

                if (!empty($orphanedSlots)) {
                    Log::channel('integration')->info("Cleaning up orphaned integration slots", [
                        'orphaned_slots' => $orphanedSlots
                    ]);

                    $redis->multi();
                    foreach ($orphanedSlots as $integrationId) {
                        $redis->srem(self::ACTIVE_INTEGRATIONS_SET_KEY, $integrationId);
                    }
                    $redis->exec();

                    $newCount = max(0, $currentCount - count($orphanedSlots));
                    $redis->set(self::ACTIVE_INTEGRATIONS_COUNT_KEY, $newCount);

                    Log::channel('integration')->info("Orphaned slots cleaned up", [
                        'removed_slots' => count($orphanedSlots),
                        'old_count' => $currentCount,
                        'new_count' => $newCount
                    ]);
                }
            } finally {
                $redis->del($lockKey);
            }

        } catch (\Exception $e) {
            Log::channel('integration')->error('Error cleaning up orphaned slots', [
                'error' => $e->getMessage()
            ]);
        }
    }
}