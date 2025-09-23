<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use App\IntegrationsQueues;

class ManageIntegrationWorker extends Command
{
    protected $signature = 'integration:worker {action : start|stop|status|restart}';
    protected $description = 'Gerencia o worker supervisor das integrações - COMANDO ÚNICO';

    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'start':
                $this->startWorker();
                break;
            case 'stop':
                $this->stopWorker();
                break;
            case 'status':
                $this->showStatus();
                break;
            case 'restart':
                $this->restartWorker();
                break;
            default:
                $this->error("Ação '{$action}' não reconhecida. Use: start, stop, status, restart");
                return 1;
        }

        return 0;
    }

    private function startWorker()
    {
        $this->info('🚀 Iniciando worker supervisor...');

        try {
            $output = Process::run('sudo supervisorctl start integration-worker:*')->output();
            $this->info('✅ Worker supervisor iniciado');
            $this->line($output);
        } catch (\Exception $e) {
            $this->error('❌ Erro ao iniciar worker: ' . $e->getMessage());
            return 1;
        }
    }

    private function stopWorker()
    {
        $this->info('🛑 Parando worker supervisor...');

        try {
            $output = Process::run('sudo supervisorctl stop integration-worker:*')->output();
            $this->info('✅ Worker supervisor parado');
            $this->line($output);
        } catch (\Exception $e) {
            $this->error('❌ Erro ao parar worker: ' . $e->getMessage());
            return 1;
        }
    }

    private function restartWorker()
    {
        $this->info('🔄 Reiniciando worker supervisor...');

        try {
            $output = Process::run('sudo supervisorctl restart integration-worker:*')->output();
            $this->info('✅ Worker supervisor reiniciado');
            $this->line($output);
        } catch (\Exception $e) {
            $this->error('❌ Erro ao reiniciar worker: ' . $e->getMessage());
            return 1;
        }
    }

    private function showStatus()
    {
        $this->info('📊 Status do Worker Supervisor');
        $this->line('═══════════════════════════════════════════════');

        try {
            
            $output = Process::run('sudo supervisorctl status integration-worker:*')->output();
            $this->line('👷 Worker Supervisor:');
            $this->line($output);

            
            $priorityJobs = DB::table('jobs')->where('queue', 'priority-integrations')->count();
            $levelJobs = DB::table('jobs')->where('queue', 'level-integrations')->count();
            $normalJobs = DB::table('jobs')->where('queue', 'normal-integrations')->count();
            $totalJobs = $priorityJobs + $levelJobs + $normalJobs;
            $this->line("📋 Jobs nas filas Redis: {$totalJobs} (Priority: {$priorityJobs}, Level: {$levelJobs}, Normal: {$normalJobs})");
            
            $processingJobs = DB::table('integrations_queues')
                ->select('id', 'integration_id', 'started_at', 'execution_time')
                ->where('status', 1)
                ->limit(50) 
                ->get();

            
            $totalProcessing = DB::table('integrations_queues')
                ->select('id')
                ->where('status', 1)
                ->count();

            if ($totalProcessing > 0) {
                $this->line("🔄 Jobs em processamento: {$totalProcessing}");

                if ($processingJobs->count() > 0) {
                    $this->line('');
                    $this->line('📊 Integrações Rodando (até 50):');

                foreach ($processingJobs as $job) {
                    $integration = DB::table('integracao_xml')
                        ->select('id', 'system')
                        ->where('id', $job->integration_id)
                        ->first();

                    $systemName = $integration->system ?? "Sistema_Desconhecido";
                    $integrationName = "{$systemName} (ID:{$job->integration_id})";
                    $startedAt = \Carbon\Carbon::parse($job->started_at);
                    $elapsed = $startedAt->diffForHumans(now(), true);

                    
                    $estimatedTotalTime = 300; 
                    $elapsedSeconds = $startedAt->diffInSeconds(now());
                    $percentage = min(round(($elapsedSeconds / $estimatedTotalTime) * 100), 95);

                    $this->line("   🔄 {$integrationName} - {$percentage}% - {$elapsed}");
                }
                }
            } else {
            $this->line("🔄 Jobs em processamento: 0");
            }

            
            $completedToday = DB::table('integrations_queues')
                ->select('id')
                ->where('status', 2)
                ->whereDate('completed_at', today())
                ->count();
            $this->line("✅ Jobs concluídos hoje: {$completedToday}");

            
            $errorToday = DB::table('integrations_queues')
                ->select('id')
                ->where('status', 3)
                ->whereDate('updated_at', today())
                ->count();
            $this->line("❌ Jobs com erro hoje: {$errorToday}");

            
            $avgTime = DB::table('integrations_queues')
                ->select('execution_time')
                ->where('status', 2)
                ->whereNotNull('execution_time')
                ->avg('execution_time');

            if ($avgTime) {
                $this->line("⏱️ Tempo médio de execução: " . round($avgTime, 2) . "s");
            }

        } catch (\Exception $e) {
            $this->error('❌ Erro ao verificar status: ' . $e->getMessage());
            return 1;
        }
    }
}