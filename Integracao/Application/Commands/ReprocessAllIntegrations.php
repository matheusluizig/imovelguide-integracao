<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Integracao;
use App\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class ReprocessAllIntegrations extends Command
{
    protected $signature = 'integration:reprocess-all {--chunk=50 : Processar em lotes}';
    protected $description = 'Reprocessa todas as integrações elegíveis - COMANDO ÚNICO';

    public function handle()
    {
        $this->info('🔄 Reprocessando Todas as Integrações');
        $this->line('═══════════════════════════════════════════════');

        // 1. Limpar filas Redis primeiro
        $this->info('🧹 Limpando filas Redis...');
        $priorityJobs = DB::table('jobs')->where('queue', 'priority-integrations')->count();
        $levelJobs = DB::table('jobs')->where('queue', 'level-integrations')->count();
        $normalJobs = DB::table('jobs')->where('queue', 'normal-integrations')->count();

        DB::table('jobs')->whereIn('queue', ['priority-integrations', 'level-integrations', 'normal-integrations'])->delete();
        $totalJobs = $priorityJobs + $levelJobs + $normalJobs;
        $this->info("✅ {$totalJobs} jobs removidos das filas Redis (Priority: {$priorityJobs}, Level: {$levelJobs}, Normal: {$normalJobs})");

        // 2. Resetar TODOS os jobs na tabela manual (reprocessamento completo)
        $this->info('🔄 Resetando TODOS os jobs para reprocessamento...');
        $totalJobs = DB::table('integrations_queues')->count();
        $orphansReset = DB::table('integrations_queues')
            ->update([
                'status' => 0, // STATUS_PENDING
                'started_at' => null,
                'completed_at' => null,
                'ended_at' => null,
                'execution_time' => null,
                'error_message' => null,
                'last_error_step' => null,
                'error_details' => null,
                'attempts' => 0,
                'updated_at' => now(),
                'created_at' => now()
            ]);
        $this->info("✅ {$orphansReset} de {$totalJobs} jobs resetados completamente");

        // 3. Buscar integrações elegíveis - Query otimizada com join
        $this->info('📋 Buscando integrações elegíveis...');
        $integrations = Integracao::select('integracao_xml.id', 'integracao_xml.user_id', 'integracao_xml.status', 'integracao_xml.system', 'integracao_xml.updated_at')
            ->join('users', 'integracao_xml.user_id', '=', 'users.id')
            ->where('integracao_xml.status', Integracao::XML_STATUS_INTEGRATED)
            ->where('users.inative', 0)
            ->get();

        $this->info("📊 Encontradas {$integrations->count()} integrações elegíveis");

        if ($integrations->count() == 0) {
            $this->warn('⚠️ Nenhuma integração elegível encontrada');
            return 0;
        }

        // 4. ✅ CORREÇÃO: Usar sistema automático - apenas resetar status para pendente
        $this->info("🚀 Resetando status para pendente (sistema automático despachará)...");

        $bar = $this->output->createProgressBar($integrations->count());
        $bar->start();

        $resetJobs = 0;
        foreach ($integrations->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $integration) {
                // ✅ CORREÇÃO: Apenas resetar status - Model Event despachará automaticamente
                // Buscar priority do usuário de forma otimizada
                $userPriority = DB::table('users')
                    ->select('integration_priority')
                    ->where('id', $integration->user_id)
                    ->value('integration_priority') ?? 0;

                IntegrationsQueues::updateOrCreate(
                    ['integration_id' => $integration->id],
                    [
                        'status' => IntegrationsQueues::STATUS_PENDING,
                        'priority' => $userPriority,
                        'started_at' => null,
                        'completed_at' => null,
                        'ended_at' => null,
                        'error_message' => null,
                        'attempts' => 0
                    ]
                );
                $resetJobs++;
                $bar->advance();
            }

            // Pequena pausa entre lotes para não sobrecarregar
            usleep(100000); // 0.1 segundo
        }

        $bar->finish();
        $this->newLine();

        // 5. Verificar resultado
        $priorityJobs = DB::table('jobs')->where('queue', 'priority-integrations')->count();
        $levelJobs = DB::table('jobs')->where('queue', 'level-integrations')->count();
        $normalJobs = DB::table('jobs')->where('queue', 'normal-integrations')->count();
        $totalJobsInQueue = $priorityJobs + $levelJobs + $normalJobs;

        $this->info("✅ {$resetJobs} jobs resetados para pendente");
        $this->info("📊 Total de jobs nas filas Redis: {$totalJobsInQueue} (Priority: {$priorityJobs}, Level: {$levelJobs}, Normal: {$normalJobs})");

        // 6. Instruções finais
        $this->newLine();
        $this->info('🎯 Próximos passos:');
        $this->line('1. Iniciar worker supervisor: sudo supervisorctl start imovelguide-integration-worker:*');
        $this->line('2. Monitorar: sudo supervisorctl status imovelguide-integration-worker:*');
        $this->line('3. Ver logs: tail -f storage/logs/worker-supervisor.log');

        return 0;
    }
}