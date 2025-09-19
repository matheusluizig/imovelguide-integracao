<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ClearIntegrationSlots extends Command
{
    protected $signature = 'integration:clear-slots {--force : Forçar limpeza sem confirmação}';
    protected $description = 'Limpa slots de integração presos no Redis';

    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('Tem certeza que deseja limpar todos os slots de integração?')) {
                $this->info('Operação cancelada.');
                return 0;
            }
        }

        try {
            $redis = app('redis');
            
            
            $activeIntegrations = $redis->smembers('active_integrations');
            $count = $redis->get('active_integrations_count') ?: 0;
            
            $this->info("Slots atuais:");
            $this->line("  Integrações ativas: " . count($activeIntegrations));
            $this->line("  Contador: {$count}");
            
            if (count($activeIntegrations) > 0) {
                $this->line("  IDs ativos: " . implode(', ', $activeIntegrations));
            }
            
            
            $redis->del('active_integrations');
            $redis->del('active_integrations_count');
            
            $this->info("✅ Slots de integração limpos com sucesso!");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Erro ao limpar slots: " . $e->getMessage());
            return 1;
        }
    }
}

