<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class DispatchPriorityIntegration extends Command
{
    protected $signature = 'integration:dispatch-priority {integration_id : ID da integração}';
    protected $description = 'Despacha uma integração específica para a fila prioritária';

    public function handle()
    {
        $integrationId = $this->argument('integration_id');

        
        $integration = Integracao::find($integrationId);
        if (!$integration) {
            $this->error("❌ Integração ID {$integrationId} não encontrada");
            return Command::FAILURE;
        }

        $this->info("🚀 Despachando integração {$integrationId} para fila prioritária...");

        try {
            
            $queue = IntegrationsQueues::firstOrCreate(
                ['integration_id' => $integrationId],
                [
                    'priority' => IntegrationsQueues::PRIORITY_PLAN,
                    'status' => IntegrationsQueues::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            
            $queue->priority = IntegrationsQueues::PRIORITY_PLAN;
            $queue->status = IntegrationsQueues::STATUS_PENDING;
            $queue->started_at = null;
            $queue->ended_at = null;
            $queue->error_message = null;
            $queue->last_error_step = null;
            $queue->error_details = null;
            $queue->attempts = 0;
            $queue->save();

            
            $integration->status = Integracao::XML_STATUS_NOT_INTEGRATED;
            $integration->save();

            
            ProcessIntegrationJob::dispatch($integrationId, 'priority-integrations');

            $this->info("✅ Integração {$integrationId} ({$integration->system}) despachada com prioridade máxima!");
            $this->line("📋 Prioridade: PLAN (máxima)");
            $this->line("🔄 Fila: integrations (prioridade máxima)");
            $this->line("⏰ Status: Pendente");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Erro ao despachar integração: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
