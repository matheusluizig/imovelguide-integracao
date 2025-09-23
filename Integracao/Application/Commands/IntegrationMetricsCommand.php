<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Carbon\Carbon;

class IntegrationMetricsCommand extends Command
{
    protected $signature = 'integration:metrics {--period=24 : Período em horas para análise}';
    protected $description = 'Exibe métricas detalhadas de performance do sistema de integração';

    public function handle()
    {
        $period = (int) $this->option('period');
        $this->info("📊 Métricas de Performance - Últimas {$period}h");
        $this->line('═══════════════════════════════════════════════');

        $this->displaySystemMetrics($period);
        $this->displayQueueMetrics($period);
        $this->displayPerformanceMetrics($period);
        $this->displayErrorMetrics($period);
        $this->displayCacheMetrics();

        return 0;
    }

    private function displaySystemMetrics(int $period): void
    {
        $this->newLine();
        $this->info('🖥️ Sistema');
        $this->line('───────────────────────────────────────────────');

        $totalIntegrations = Integracao::count();
        $activeIntegrations = Integracao::where('status', Integracao::XML_STATUS_INTEGRATED)->count();
        $processingIntegrations = Integracao::whereIn('status', [
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE
        ])->count();

        $this->line("Total de Integrações: {$totalIntegrations}");
        $this->line("Integrações Ativas: {$activeIntegrations}");
        $this->line("Em Processamento: {$processingIntegrations}");

        
        $activeWorkers = $this->getActiveWorkersCount();
        $this->line("Workers Ativos: {$activeWorkers}");
    }

    private function displayQueueMetrics(int $period): void
    {
        $this->newLine();
        $this->info('📋 Fila de Processamento');
        $this->line('───────────────────────────────────────────────');

        $since = now()->subHours($period);

        $pendingJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_PENDING)->count();
        $processingJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count();
        $completedJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $since)->count();
        $errorJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_ERROR)
            ->where('updated_at', '>=', $since)->count();

        $this->line("Jobs Pendentes: {$pendingJobs}");
        $this->line("Jobs em Processamento: {$processingJobs}");
        $this->line("Jobs Concluídos ({$period}h): {$completedJobs}");
        $this->line("Jobs com Erro ({$period}h): {$errorJobs}");

        
        $totalProcessed = $completedJobs + $errorJobs;
        $successRate = $totalProcessed > 0 ? round(($completedJobs / $totalProcessed) * 100, 2) : 0;
        $this->line("Taxa de Sucesso: {$successRate}%");
    }

    private function displayPerformanceMetrics(int $period): void
    {
        $this->newLine();
        $this->info('⚡ Performance');
        $this->line('───────────────────────────────────────────────');

        $since = now()->subHours($period);

        
        $avgExecutionTime = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $since)
            ->whereNotNull('execution_time')
            ->avg('execution_time');

        if ($avgExecutionTime) {
            $this->line("Tempo Médio de Execução: " . round($avgExecutionTime, 2) . "s");
        }

        
        $jobsPerHour = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $since)
            ->count() / $period;

        $this->line("Throughput: " . round($jobsPerHour, 2) . " jobs/hora");

        
        $slowestJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $since)
            ->whereNotNull('execution_time')
            ->orderBy('execution_time', 'desc')
            ->limit(5)
            ->get();

        if ($slowestJobs->count() > 0) {
            $this->line("Jobs Mais Lentos:");
            foreach ($slowestJobs as $job) {
                $integration = Integracao::find($job->integration_id);
                $systemName = $integration ? ($integration->system ?? 'Unknown') : 'Unknown';
                $this->line("  • {$systemName} (ID:{$job->integration_id}): " . round($job->execution_time, 2) . "s");
            }
        }
    }

    private function displayErrorMetrics(int $period): void
    {
        $this->newLine();
        $this->info('❌ Erros');
        $this->line('───────────────────────────────────────────────');

        $since = now()->subHours($period);

        
        $errorTypes = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_ERROR)
            ->where('updated_at', '>=', $since)
            ->whereNotNull('last_error_step')
            ->select('last_error_step', DB::raw('count(*) as count'))
            ->groupBy('last_error_step')
            ->orderBy('count', 'desc')
            ->get();

        if ($errorTypes->count() > 0) {
            $this->line("Erros por Etapa:");
            foreach ($errorTypes as $error) {
                $this->line("  • {$error->last_error_step}: {$error->count}");
            }
        }

        
        $recentErrors = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_ERROR)
            ->where('updated_at', '>=', $since)
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();

        if ($recentErrors->count() > 0) {
            $this->line("Erros Recentes:");
            foreach ($recentErrors as $error) {
                $integration = Integracao::find($error->integration_id);
                $systemName = $integration ? ($integration->system ?? 'Unknown') : 'Unknown';
                $timeAgo = Carbon::parse($error->updated_at)->diffForHumans();
                $this->line("  • {$systemName} (ID:{$error->integration_id}): {$error->error_message} ({$timeAgo})");
            }
        }
    }

    private function displayCacheMetrics(): void
    {
        $this->newLine();
        $this->info('💾 Cache');
        $this->line('───────────────────────────────────────────────');

        $cacheDriver = config('cache.default');
        $this->line("Driver: {$cacheDriver}");

        if ($cacheDriver === 'redis') {
            try {
                $redis = app('redis');
                $info = $redis->info();

                $this->line("Conexões Ativas: " . ($info['connected_clients'] ?? 'N/A'));
                $this->line("Memória Usada: " . ($info['used_memory_human'] ?? 'N/A'));
                $this->line("Chaves no Cache: " . ($info['db0'] ?? 'N/A'));

                
                $integrationKeys = $redis->keys('integration_*');
                $this->line("Chaves de Integração: " . count($integrationKeys));

            } catch (\Exception $e) {
                $this->warn("Erro ao obter informações do Redis: " . $e->getMessage());
            }
        }
    }

    private function getActiveWorkersCount(): int
    {
        try {
            $output = shell_exec('sudo supervisorctl status imovelguide-integration-worker:* 2>/dev/null | grep RUNNING | wc -l');
            return (int) trim($output);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getDetailedMetrics(int $integrationId): array
    {
        $integration = Integracao::with(['user', 'queue'])->find($integrationId);
        if (!$integration) {
            return [];
        }

        $queue = $integration->queue;
        $metrics = [
            'integration' => [
                'id' => $integration->id,
                'status' => $integration->status,
                'system' => $integration->system ?? 'unknown',
                'created_at' => $integration->created_at,
                'updated_at' => $integration->updated_at
            ],
            'queue' => $queue ? [
                'status' => $queue->status,
                'priority' => $queue->priority,
                'started_at' => $queue->started_at,
                'completed_at' => $queue->completed_at,
                'execution_time' => $queue->execution_time,
                'attempts' => $queue->attempts,
                'error_message' => $queue->error_message
            ] : null,
            'user' => [
                'id' => $integration->user->id,
                'name' => $integration->user->name,
                'active' => $integration->user->inative == 0,
                'level' => $integration->user->level ?? 0
            ]
        ];

        return $metrics;
    }
}
