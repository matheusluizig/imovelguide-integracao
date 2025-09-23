<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Carbon\Carbon;

class IntegrationHealthCheck extends Command
{
    protected $signature = 'integration:health-check 
                            {--fix : Corrigir problemas automaticamente}
                            {--detailed : Mostrar informações detalhadas}';

    protected $description = 'Verifica a saúde do sistema de integrações';

    public function handle()
    {
        $this->info('🏥 Verificando saúde do sistema de integrações...');
        $this->newLine();

        $health = $this->performHealthCheck();

        $this->displayHealthReport($health);

        if ($this->option('fix') && !$health['healthy']) {
            $this->fixHealthIssues($health['issues']);
        }

        return $health['healthy'] ? 0 : 1;
    }

    private function performHealthCheck(): array
    {
        $health = [
            'healthy' => true,
            'issues' => [],
            'metrics' => []
        ];

        
        $this->checkWorkers($health);

        
        $this->checkQueues($health);

        
        $this->checkIntegrations($health);

        
        $this->checkPerformance($health);

        
        $this->checkResources($health);

        return $health;
    }

    private function checkWorkers(array &$health): void
    {
        $this->line('👷 Verificando workers...');

        try {
            $output = Process::run('sudo supervisorctl status integration-worker:*')->output();
            $lines = explode("\n", trim($output));
            
            $running = 0;
            $stopped = 0;
            $total = 0;

            foreach ($lines as $line) {
                if (empty($line)) continue;
                $total++;
                
                if (strpos($line, 'RUNNING') !== false) {
                    $running++;
                } elseif (strpos($line, 'STOPPED') !== false) {
                    $stopped++;
                }
            }

            $health['metrics']['workers'] = [
                'total' => $total,
                'running' => $running,
                'stopped' => $stopped
            ];

            if ($running === 0) {
                $health['healthy'] = false;
                $health['issues'][] = 'Nenhum worker ativo';
            } elseif ($stopped > 0) {
                $health['issues'][] = "{$stopped} workers parados";
            }

            $this->line("   ✅ Workers: {$running}/{$total} ativos");

        } catch (\Exception $e) {
            $health['healthy'] = false;
            $health['issues'][] = 'Erro ao verificar workers: ' . $e->getMessage();
            $this->line("   ❌ Erro ao verificar workers");
        }
    }

    private function checkQueues(array &$health): void
    {
        $this->line('📋 Verificando filas...');

        try {
            $redisJobs = DB::table('jobs')->whereIn('queue', [
                'priority-integrations', 'level-integrations', 'normal-integrations', 'image-processing'
            ])->count();

            $pendingJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_PENDING)->count();
            $processingJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count();
            $stuckJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->where('started_at', '<', now()->subHours(2))
                ->count();

            $health['metrics']['queues'] = [
                'redis_jobs' => $redisJobs,
                'pending_jobs' => $pendingJobs,
                'processing_jobs' => $processingJobs,
                'stuck_jobs' => $stuckJobs
            ];

            if ($stuckJobs > 0) {
                $health['healthy'] = false;
                $health['issues'][] = "{$stuckJobs} jobs travados";
            }

            if ($redisJobs > 1000) {
                $health['issues'][] = "Fila Redis muito grande: {$redisJobs} jobs";
            }

            $this->line("   ✅ Redis: {$redisJobs} | Pendentes: {$pendingJobs} | Processando: {$processingJobs}");

        } catch (\Exception $e) {
            $health['healthy'] = false;
            $health['issues'][] = 'Erro ao verificar filas: ' . $e->getMessage();
            $this->line("   ❌ Erro ao verificar filas");
        }
    }

    private function checkIntegrations(array &$health): void
    {
        $this->line('🔄 Verificando integrações...');

        try {
            $total = Integracao::count();
            $active = Integracao::where('status', Integracao::XML_STATUS_INTEGRATED)->count();
            $processing = Integracao::whereIn('status', [
                Integracao::XML_STATUS_IN_UPDATE_BOTH,
                Integracao::XML_STATUS_IN_DATA_UPDATE,
                Integracao::XML_STATUS_IN_IMAGE_UPDATE
            ])->count();
            $errors = Integracao::where('status', Integracao::XML_STATUS_CRM_ERRO)->count();
            $stuck = Integracao::whereIn('status', [
                Integracao::XML_STATUS_IN_UPDATE_BOTH,
                Integracao::XML_STATUS_IN_DATA_UPDATE,
                Integracao::XML_STATUS_IN_IMAGE_UPDATE
            ])->where('updated_at', '<', now()->subHours(2))->count();

            $health['metrics']['integrations'] = [
                'total' => $total,
                'active' => $active,
                'processing' => $processing,
                'errors' => $errors,
                'stuck' => $stuck
            ];

            if ($stuck > 0) {
                $health['healthy'] = false;
                $health['issues'][] = "{$stuck} integrações travadas";
            }

            $this->line("   ✅ Total: {$total} | Ativas: {$active} | Processando: {$processing} | Erros: {$errors}");

        } catch (\Exception $e) {
            $health['healthy'] = false;
            $health['issues'][] = 'Erro ao verificar integrações: ' . $e->getMessage();
            $this->line("   ❌ Erro ao verificar integrações");
        }
    }

    private function checkPerformance(array &$health): void
    {
        $this->line('⚡ Verificando performance...');

        try {
            $avgExecutionTime = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
                ->where('completed_at', '>=', now()->subDays(7))
                ->whereNotNull('execution_time')
                ->avg('execution_time') ?? 0;

            $successRate = $this->calculateSuccessRate();

            $health['metrics']['performance'] = [
                'avg_execution_time' => $avgExecutionTime,
                'success_rate' => $successRate
            ];

            if ($avgExecutionTime > 1800) { 
                $health['issues'][] = "Tempo médio de execução muito alto: " . round($avgExecutionTime) . "s";
            }

            if ($successRate < 80) {
                $health['issues'][] = "Taxa de sucesso baixa: " . round($successRate, 1) . "%";
            }

            $this->line("   ✅ Tempo médio: " . round($avgExecutionTime) . "s | Taxa sucesso: " . round($successRate, 1) . "%");

        } catch (\Exception $e) {
            $health['healthy'] = false;
            $health['issues'][] = 'Erro ao verificar performance: ' . $e->getMessage();
            $this->line("   ❌ Erro ao verificar performance");
        }
    }

    private function checkResources(array &$health): void
    {
        $this->line('💾 Verificando recursos...');

        try {
            
            $redisInfo = shell_exec('redis-cli info memory 2>/dev/null | grep used_memory_human');
            $memoryUsage = trim($redisInfo);

            
            $diskUsage = shell_exec('df -h /var/www 2>/dev/null | tail -1 | awk \'{print $5}\'');
            $diskUsage = trim($diskUsage);

            $health['metrics']['resources'] = [
                'redis_memory' => $memoryUsage,
                'disk_usage' => $diskUsage
            ];

            if ($diskUsage && (int) $diskUsage > 90) {
                $health['issues'][] = "Espaço em disco baixo: {$diskUsage}";
            }

            $this->line("   ✅ Redis: {$memoryUsage} | Disco: {$diskUsage}");

        } catch (\Exception $e) {
            $health['issues'][] = 'Erro ao verificar recursos: ' . $e->getMessage();
            $this->line("   ⚠️  Erro ao verificar recursos");
        }
    }

    private function calculateSuccessRate(): float
    {
        $total = IntegrationsQueues::where('completed_at', '>=', now()->subDays(7))->count();
        $successful = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', now()->subDays(7))
            ->count();

        return $total > 0 ? ($successful / $total) * 100 : 0;
    }

    private function displayHealthReport(array $health): void
    {
        $this->newLine();
        $this->line('═══════════════════════════════════════════════');
        
        if ($health['healthy']) {
            $this->line('✅ SISTEMA SAUDÁVEL');
        } else {
            $this->line('❌ PROBLEMAS DETECTADOS');
        }
        
        $this->line('═══════════════════════════════════════════════');

        if (!empty($health['issues'])) {
            $this->line('⚠️  PROBLEMAS:');
            foreach ($health['issues'] as $issue) {
                $this->line("   - {$issue}");
            }
            $this->newLine();
        }

        if ($this->option('detailed')) {
            $this->displayDetailedMetrics($health['metrics']);
        }
    }

    private function displayDetailedMetrics(array $metrics): void
    {
        $this->line('📊 MÉTRICAS DETALHADAS:');
        
        if (isset($metrics['workers'])) {
            $w = $metrics['workers'];
            $this->line("   Workers: {$w['running']}/{$w['total']} ativos");
        }
        
        if (isset($metrics['queues'])) {
            $q = $metrics['queues'];
            $this->line("   Filas: Redis={$q['redis_jobs']}, Pendentes={$q['pending_jobs']}, Processando={$q['processing_jobs']}");
        }
        
        if (isset($metrics['integrations'])) {
            $i = $metrics['integrations'];
            $this->line("   Integrações: Total={$i['total']}, Ativas={$i['active']}, Processando={$i['processing']}, Erros={$i['errors']}");
        }
        
        if (isset($metrics['performance'])) {
            $p = $metrics['performance'];
            $this->line("   Performance: Tempo médio={$p['avg_execution_time']}s, Taxa sucesso={$p['success_rate']}%");
        }
        
        $this->newLine();
    }

    private function fixHealthIssues(array $issues): void
    {
        $this->info('🔧 Corrigindo problemas...');

        foreach ($issues as $issue) {
            if (strpos($issue, 'Nenhum worker ativo') !== false) {
                $this->startWorkers();
            } elseif (strpos($issue, 'workers parados') !== false) {
                $this->restartWorkers();
            } elseif (strpos($issue, 'jobs travados') !== false) {
                $this->resetStuckJobs();
            } elseif (strpos($issue, 'integrações travadas') !== false) {
                $this->resetStuckIntegrations();
            }
        }

        $this->info('✅ Problemas corrigidos');
    }

    private function startWorkers(): void
    {
        try {
            Process::run('sudo supervisorctl start integration-worker:*');
            $this->line('   ✅ Workers iniciados');
        } catch (\Exception $e) {
            $this->line("   ❌ Erro ao iniciar workers: {$e->getMessage()}");
        }
    }

    private function restartWorkers(): void
    {
        try {
            Process::run('sudo supervisorctl restart integration-worker:*');
            $this->line('   ✅ Workers reiniciados');
        } catch (\Exception $e) {
            $this->line("   ❌ Erro ao reiniciar workers: {$e->getMessage()}");
        }
    }

    private function resetStuckJobs(): void
    {
        $stuckJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
            ->where('started_at', '<', now()->subHours(2))
            ->update([
                'status' => IntegrationsQueues::STATUS_PENDING,
                'started_at' => null,
                'error_message' => 'Resetado por health check'
            ]);

        $this->line("   ✅ {$stuckJobs} jobs travados resetados");
    }

    private function resetStuckIntegrations(): void
    {
        $stuckIntegrations = Integracao::whereIn('status', [
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE
        ])->where('updated_at', '<', now()->subHours(2))
        ->update(['status' => Integracao::XML_STATUS_IN_ANALYSIS]);

        $this->line("   ✅ {$stuckIntegrations} integrações travadas resetadas");
    }
}
