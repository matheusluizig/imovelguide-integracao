<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class DispatchPriorityIntegration extends Command
{
    protected $signature = 'integration:dispatch-priority {integration_ids* : Um ou mais IDs de integrações (aceita separado por espaço ou vírgula)}';
    protected $description = 'Despacha uma integração específica para a fila prioritária';

    public function handle()
    {
        $args = (array) $this->argument('integration_ids');

        $flat = [];
        foreach ($args as $arg) {
            $parts = array_filter(array_map('trim', explode(',', (string) $arg)));
            foreach ($parts as $p) {
                if ($p !== '') {
                    $flat[] = $p;
                }
            }
        }

        $ids = array_values(array_unique($flat));

        if (empty($ids)) {
            $this->error('❌ Nenhum ID informado.');
            return Command::FAILURE;
        }

        $this->info('🚀 Despachando integrações para fila prioritária: ' . implode(', ', $ids));

        $success = 0;
        $fail = 0;

        foreach ($ids as $integrationId) {
            $integration = Integracao::find($integrationId);
            if (!$integration) {
                $this->error("❌ Integração ID {$integrationId} não encontrada");
                $fail++;
                continue;
            }

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
                $success++;
            } catch (\Exception $e) {
                $this->error("❌ Erro ao despachar integração {$integrationId}: " . $e->getMessage());
                $fail++;
            }
        }

        $this->line("Resumo: ✅ {$success} sucesso(s), ❌ {$fail} falha(s)");
        return $fail > 0 && $success === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}