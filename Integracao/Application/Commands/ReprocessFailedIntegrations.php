<?php

namespace App\Integracao\Application\Commands;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReprocessFailedIntegrations extends Command
{
    protected $signature = 'integration:reprocess-failed 
                            {--force : Força o reprocessamento sem confirmação}
                            {--status= : Status específico para reprocessar (0,1,4)}
                            {--limit=100 : Limite de integrações por execução}
                            {--clean-old : Remove registros de fila com mais de 30 dias}';

    protected $description = 'Limpa todas as filas e reprocessa integrações com status diferentes de 2 (concluído)';

    public function handle(): int
    {
        $this->info('🔄 INICIANDO REPROCESSAMENTO DE INTEGRAÇÕES FALHADAS');
        $this->newLine();

        
        if (!$this->option('force')) {
            if (!$this->confirm('⚠️  Esta operação irá limpar TODAS as filas e reprocessar integrações. Continuar?')) {
                $this->info('❌ Operação cancelada pelo usuário.');
                return 0;
            }
        }

        $startTime = microtime(true);
        $processed = 0;
        $errors = 0;

        try {
            
            $this->clearAllQueues();

            
            if ($this->option('clean-old')) {
                $this->cleanOldQueueRecords();
            }

            
            $processed = $this->reprocessIntegrations();

            $executionTime = microtime(true) - $startTime;

            $this->newLine();
            $this->info('✅ REPROCESSAMENTO CONCLUÍDO COM SUCESSO!');
            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Integrações reprocessadas', $processed],
                    ['Erros encontrados', $errors],
                    ['Tempo de execução', number_format($executionTime, 2) . 's'],
                ]
            );

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Erro durante o reprocessamento: ' . $e->getMessage());
            Log::error('ReprocessFailedIntegrations error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    private function clearAllQueues(): void
    {
        $this->info('🧹 Limpando todas as filas...');

        $queues = ['priority-integrations', 'level-integrations', 'normal-integrations', 'image-processing'];
        $totalCleared = 0;

        foreach ($queues as $queue) {
            $count = DB::table('jobs')->where('queue', $queue)->count();
            if ($count > 0) {
                DB::table('jobs')->where('queue', $queue)->delete();
                $this->line("  ✅ Fila '{$queue}': {$count} jobs removidos");
                $totalCleared += $count;
            } else {
                $this->line("  ⏭️  Fila '{$queue}': vazia");
            }
        }

        $this->info("📊 Total de jobs removidos: {$totalCleared}");
        $this->newLine();
    }

    private function reprocessIntegrations(): int
    {
        $this->info('🔄 Reprocessando integrações com status diferentes de 2...');

        $statusFilter = $this->option('status');
        $limit = (int) $this->option('limit');

        
        $query = Integracao::join('integrations_queues', 'integracao_xml.id', '=', 'integrations_queues.integration_id')
            ->join('users', 'integracao_xml.user_id', '=', 'users.id')
            ->where('users.inative', 0)
            ->where('integrations_queues.status', '!=', IntegrationsQueues::STATUS_DONE);

        
        if ($statusFilter) {
            $statuses = array_map('intval', explode(',', $statusFilter));
            $query->whereIn('integrations_queues.status', $statuses);
        }

        $integrations = $query->select('integracao_xml.*', 'integrations_queues.priority')
            ->orderBy('integrations_queues.priority', 'desc') 
            ->orderBy('integracao_xml.updated_at', 'desc')
            ->limit($limit)
            ->get();

        if ($integrations->isEmpty()) {
            $this->warn('⚠️  Nenhuma integração encontrada para reprocessar.');
            return 0;
        }

        $this->info("📋 Encontradas {$integrations->count()} integrações para reprocessar");
        $this->newLine();

        $processed = 0;
        $progressBar = $this->output->createProgressBar($integrations->count());
        $progressBar->start();

        foreach ($integrations as $integration) {
            try {
                
                DB::transaction(function() use ($integration) {
                    IntegrationsQueues::where('integration_id', $integration->id)
                        ->update([
                            'status' => IntegrationsQueues::STATUS_PENDING,
                            'started_at' => null,
                            'ended_at' => null,
                            'error_message' => null,
                            'updated_at' => now()
                        ]);

                    
                    if ($integration->status !== Integracao::XML_STATUS_NOT_INTEGRATED) {
                        $integration->update(['status' => Integracao::XML_STATUS_NOT_INTEGRATED]);
                    }
                });

                
                $queueName = 'normal-integrations';
                if ($integration->priority == IntegrationsQueues::PRIORITY_PLAN) {
                    $queueName = 'priority-integrations';
                } elseif ($integration->priority == IntegrationsQueues::PRIORITY_LEVEL) {
                    $queueName = 'level-integrations';
                }

                
                ProcessIntegrationJob::dispatch($integration->id, $queueName);

                $processed++;

            } catch (\Exception $e) {
                $this->error("❌ Erro ao reprocessar integração {$integration->id}: " . $e->getMessage());
                Log::error('ReprocessFailedIntegrations integration error', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage()
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        return $processed;
    }

    private function cleanOldQueueRecords(): void
    {
        $this->info('🧹 Limpando registros antigos da tabela integrations_queues...');

        $thirtyDaysAgo = now()->subDays(30);
        
        $deletedCount = IntegrationsQueues::where('created_at', '<', $thirtyDaysAgo)
            ->where('status', IntegrationsQueues::STATUS_DONE)
            ->delete();

        $this->info("📊 Registros antigos removidos: {$deletedCount}");
        $this->newLine();
    }
}