<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Illuminate\Support\Facades\Log;

class CleanupOrphanedIntegrationSlots extends Command
{
    protected $signature = 'integration:cleanup-orphaned-slots';
    protected $description = 'Limpa slots órfãos de integração no Redis';

    public function handle()
    {
        $this->info('Iniciando limpeza de slots órfãos...');

        try {
            $redis = Redis::connection('queue');
            
            // Verificar slots ativos
            $activeIntegrations = $redis->smembers('imovelguide_database_active_integrations');
            $currentCount = $redis->get('imovelguide_database_active_integrations_count') ?: 0;
            
            $this->info("Slots ativos encontrados: " . count($activeIntegrations));
            $this->info("Contador atual: " . $currentCount);

            if (empty($activeIntegrations) && $currentCount == 0) {
                $this->info('Nenhum slot ativo encontrado.');
                return;
            }

            // Se não há slots ativos mas o contador não é zero, resetar
            if (empty($activeIntegrations) && $currentCount > 0) {
                $this->warn("Contador incorreto detectado. Resetando para 0.");
                $redis->set('imovelguide_database_active_integrations_count', 0);
                $this->info("Contador resetado para 0.");
                return;
            }

            $orphanedSlots = [];
            foreach ($activeIntegrations as $integrationId) {
                // Verificar se a integração ainda está em processamento no banco
                $queue = IntegrationsQueues::where('integration_id', $integrationId)
                    ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                    ->first();

                if (!$queue) {
                    $orphanedSlots[] = $integrationId;
                    $this->warn("Slot órfão encontrado: {$integrationId}");
                } else {
                    $this->info("Slot válido: {$integrationId} (processando desde {$queue->started_at})");
                }
            }

            if (empty($orphanedSlots)) {
                $this->info('Nenhum slot órfão encontrado.');
                return;
            }

            // Confirmar limpeza
            if ($this->confirm("Deseja limpar " . count($orphanedSlots) . " slots órfãos?")) {
                foreach ($orphanedSlots as $integrationId) {
                    $redis->srem('imovelguide_database_active_integrations', $integrationId);
                }

                // Ajustar contador
                $currentCount = $redis->get('imovelguide_database_active_integrations_count') ?: 0;
                $newCount = max(0, $currentCount - count($orphanedSlots));
                $redis->set('imovelguide_database_active_integrations_count', $newCount);

                $this->info("Slots órfãos limpos com sucesso!");
                $this->info("Slots removidos: " . count($orphanedSlots));
                $this->info("Novo contador: {$newCount}");

                Log::info("Orphaned integration slots cleaned up manually", [
                    'removed_slots' => $orphanedSlots,
                    'new_count' => $newCount
                ]);
            } else {
                $this->info('Operação cancelada.');
            }

        } catch (\Exception $e) {
            $this->error("Erro ao limpar slots órfãos: " . $e->getMessage());
            Log::error("Error cleaning up orphaned slots", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
