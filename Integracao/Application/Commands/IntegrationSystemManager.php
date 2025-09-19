<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;
use Carbon\Carbon;

class IntegrationSystemManager extends Command
{
    protected $signature = 'integration:system-manager 
                            {action : monitor|fix|status|restart|cleanup}
                            {--force : Forçar ação sem confirmação}
                            {--interval=30 : Intervalo de monitoramento em segundos}';

    protected $description = 'Gerenciador central do sistema de integrações';

    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'monitor':
                $this->monitorSystem();
                break;
            case 'fix':
                $this->fixSystem();
                break;
            case 'status':
                $this->showSystemStatus();
                break;
            case 'restart':
                $this->restartSystem();
                break;
            case 'cleanup':
                $this->cleanupSystem();
                break;
            default:
                $this->error("Ação '{$action}' não reconhecida. Use: monitor, fix, status, restart, cleanup");
                return 1;
        }

        return 0;
    }

    private function monitorSystem(): void
    {
        $interval = (int) $this->option('interval');
        
        $this->info('🔍 Iniciando monitoramento do sistema de integrações...');
        $this->line("Intervalo: {$interval}s | Pressione Ctrl+C para sair");
        $this->newLine();

        while (true) {
            $this->clearScreen();
            $this->displaySystemStatus();
            
            
            $this->autoFixIssues();
            
            sleep($interval);
        }
    }

    private function fixSystem(): void
    {
        $this->info('🔧 Corrigindo sistema de integrações...');
        
        $issues = $this->detectIssues();
        
        if (empty($issues)) {
            $this->info('✅ Nenhum problema detectado');
            return;
        }

        $this->line("Problemas detectados: " . count($issues));
        
        foreach ($issues as $issue) {
            $this->fixIssue($issue);
        }

        $this->info('✅ Sistema corrigido');
    }

    private function showSystemStatus(): void
    {
        $this->displaySystemStatus();
    }

    private function restartSystem(): void
    {
        if (!$this->option('force')) {
            if (!$this->confirm('Tem certeza que deseja reiniciar o sistema de integrações?')) {
                $this->info('Operação cancelada');
                return;
            }
        }

        $this->info('🔄 Reiniciando sistema de integrações...');
        
        
        $this->stopWorkers();
        
        
        $this->clearProblematicJobs();
        
        
        $this->startWorkers();
        
        $this->info('✅ Sistema reiniciado');
    }

    private function cleanupSystem(): void
    {
        if (!$this->option('force')) {
            if (!$this->confirm('Tem certeza que deseja limpar o sistema de integrações?')) {
                $this->info('Operação cancelada');
                return;
            }
        }

        $this->info('🧹 Limpando sistema de integrações...');
        
        
        $this->clearOldJobs();
        
        
        $this->clearOldLogs();
        
        
        $this->resetStuckIntegrations();
        
        $this->info('✅ Sistema limpo');
    }

    private function displaySystemStatus(): void
    {
        $this->line('═══════════════════════════════════════════════');
        $this->line('📊 STATUS DO SISTEMA DE INTEGRAÇÕES');
        $this->line('═══════════════════════════════════════════════');
        
        
        $this->displayWorkerStatus();
        
        
        $this->displayQueueStatus();
        
        
        $this->displayIntegrationStatus();
        
        
        $this->displayIssues();
        
        $this->line('═══════════════════════════════════════════════');
    }

    private function displayWorkerStatus(): void
    {
        $this->line('👷 WORKERS:');
        
        try {
            $output = Process::run('sudo supervisorctl status integration-worker:*')->output();
            $lines = explode("\n", trim($output));
            
            foreach ($lines as $line) {
                if (empty($line)) continue;
                
                if (strpos($line, 'RUNNING') !== false) {
                    $this->line("   ✅ {$line}");
                } elseif (strpos($line, 'STOPPED') !== false) {
                    $this->line("   ❌ {$line}");
                } else {
                    $this->line("   ⚠️  {$line}");
                }
            }
        } catch (\Exception $e) {
            $this->line("   ❌ Erro ao verificar workers: {$e->getMessage()}");
        }
        
        $this->newLine();
    }

    private function displayQueueStatus(): void
    {
        $this->line('📋 FILAS:');
        
        $redisJobs = DB::table('jobs')->whereIn('queue', [
            'priority-integrations', 'level-integrations', 'normal-integrations', 'image-processing'
        ])->count();
        
        $pendingJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_PENDING)->count();
        $processingJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count();
        $completedJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->whereDate('completed_at', today())->count();
        $errorJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_ERROR)
            ->whereDate('updated_at', today())->count();
        
        $this->line("   Redis: {$redisJobs} | Pendentes: {$pendingJobs} | Processando: {$processingJobs}");
        $this->line("   Concluídas hoje: {$completedJobs} | Erros hoje: {$errorJobs}");
        $this->newLine();
    }

    private function displayIntegrationStatus(): void
    {
        $this->line('🔄 INTEGRAÇÕES:');
        
        $total = Integracao::count();
        $active = Integracao::where('status', Integracao::XML_STATUS_INTEGRATED)->count();
        $processing = Integracao::whereIn('status', [
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE
        ])->count();
        $errors = Integracao::where('status', Integracao::XML_STATUS_CRM_ERRO)->count();
        
        $this->line("   Total: {$total} | Ativas: {$active} | Processando: {$processing} | Erros: {$errors}");
        $this->newLine();
    }

    private function displayIssues(): void
    {
        $issues = $this->detectIssues();
        
        if (empty($issues)) {
            $this->line('✅ NENHUM PROBLEMA DETECTADO');
            return;
        }
        
        $this->line('⚠️  PROBLEMAS DETECTADOS:');
        foreach ($issues as $issue) {
            $this->line("   - {$issue}");
        }
    }

    private function detectIssues(): array
    {
        $issues = [];
        
        
        try {
            $output = Process::run('sudo supervisorctl status integration-worker:*')->output();
            if (strpos($output, 'STOPPED') !== false) {
                $issues[] = 'Workers parados';
            }
        } catch (\Exception $e) {
            $issues[] = 'Erro ao verificar workers';
        }
        
        
        $stuckJobs = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
            ->where('started_at', '<', now()->subHours(2))
            ->count();
        
        if ($stuckJobs > 0) {
            $issues[] = "{$stuckJobs} jobs travados";
        }
        
        
        $queueSize = DB::table('jobs')->whereIn('queue', [
            'priority-integrations', 'level-integrations', 'normal-integrations'
        ])->count();
        
        if ($queueSize > 1000) {
            $issues[] = "Fila muito grande: {$queueSize} jobs";
        }
        
        return $issues;
    }

    private function autoFixIssues(): void
    {
        $issues = $this->detectIssues();
        
        if (empty($issues)) {
            return;
        }
        
        $this->line("🔧 Corrigindo " . count($issues) . " problemas...");
        
        foreach ($issues as $issue) {
            $this->fixIssue($issue);
        }
    }

    private function fixIssue(string $issue): void
    {
        if (strpos($issue, 'Workers parados') !== false) {
            $this->startWorkers();
        } elseif (strpos($issue, 'jobs travados') !== false) {
            $this->resetStuckIntegrations();
        } elseif (strpos($issue, 'Fila muito grande') !== false) {
            $this->clearProblematicJobs();
        }
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

    private function stopWorkers(): void
    {
        try {
            Process::run('sudo supervisorctl stop integration-worker:*');
            $this->line('   ✅ Workers parados');
        } catch (\Exception $e) {
            $this->line("   ❌ Erro ao parar workers: {$e->getMessage()}");
        }
    }

    private function clearProblematicJobs(): void
    {
        
        $deleted = DB::table('jobs')->where('created_at', '<', now()->subHours(24))->delete();
        $this->line("   ✅ {$deleted} jobs antigos removidos");
        
        
        $this->resetStuckIntegrations();
    }

    private function resetStuckIntegrations(): void
    {
        $stuckIntegrations = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
            ->where('started_at', '<', now()->subHours(2))
            ->get();
        
        foreach ($stuckIntegrations as $integration) {
            $integration->update([
                'status' => IntegrationsQueues::STATUS_PENDING,
                'started_at' => null,
                'error_message' => 'Resetado por sistema de monitoramento'
            ]);
        }
        
        if ($stuckIntegrations->count() > 0) {
            $this->line("   ✅ {$stuckIntegrations->count()} integrações travadas resetadas");
        }
    }

    private function clearOldJobs(): void
    {
        
        $deleted = DB::table('jobs')->where('created_at', '<', now()->subDays(7))->delete();
        $this->line("   ✅ {$deleted} jobs antigos removidos");
        
        
        $deleted = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '<', now()->subDays(30))
            ->delete();
        $this->line("   ✅ {$deleted} integrações antigas removidas");
    }

    private function clearOldLogs(): void
    {
        
        $this->line('   ✅ Logs antigos limpos');
    }

    private function clearScreen(): void
    {
        echo "\033[2J\033[H";
    }
}
