<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Integracao\Application\Services\IntegrationSlotManager;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Domain\Entities\Integracao;

/**
 * Comando para limpeza completa e reset do sistema de integração
 *
 * Uso: php artisan integration:clean-system --force
 */
class CleanIntegrationSystem extends Command
{
    protected $signature = 'integration:clean-system {--force : Força execução sem confirmação} {--dry-run : Apenas simula, não executa}';
    protected $description = 'Limpa e reseta completamente o sistema de integração (Redis + Banco)';

    private IntegrationSlotManager $slotManager;

    public function __construct(IntegrationSlotManager $slotManager)
    {
        parent::__construct();
        $this->slotManager = $slotManager;
    }

    public function handle(): int
    {
        $this->info("🔧 Sistema de Limpeza de Integração v2.0");
        $this->newLine();

        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Esta operação irá resetar TODAS as integrações. Continuar?')) {
                $this->info('Operação cancelada.');
                return 0;
            }
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn("🧪 MODO DRY-RUN: Apenas simulando...");
            $this->newLine();
        }

        // 1. Análise do estado atual
        $this->analyzeCurrentState();

        // 2. Limpeza Redis
        $this->cleanRedisSlots($isDryRun);

        // 3. Reset database
        $this->resetDatabase($isDryRun);

        // 4. Verificação final
        $this->finalVerification();

        $this->newLine();
        $this->info($isDryRun ? "🧪 Simulação concluída!" : "✅ Sistema limpo com sucesso!");

        return 0;
    }

    /**
     * Analisar estado atual do sistema
     */
    private function analyzeCurrentState(): void
    {
        $this->info("📊 Analisando estado atual...");

        // Redis stats
        $stats = $this->slotManager->getSlotStats();
        $this->line("  Redis Slots:");
        $this->line("    Ativos: {$stats['active_slots']}");
        $this->line("    Contador: {$stats['counter']}");
        $this->line("    Disponíveis: {$stats['available_slots']}");

        if (!empty($stats['active_integration_ids'])) {
            $this->line("    IDs Ativos: " . implode(', ', $stats['active_integration_ids']));
        }

        // Database stats
        $queueStats = IntegrationsQueues::selectRaw('
            status,
            COUNT(*) as count,
            MIN(started_at) as oldest_started,
            MAX(updated_at) as last_updated
        ')->groupBy('status')->get();

        $this->line("  Database Queue:");
        foreach ($queueStats as $stat) {
            $statusName = match($stat->status) {
                0 => 'PENDING',
                1 => 'IN_PROCESS',
                2 => 'DONE',
                3 => 'STOPPED',
                4 => 'ERROR',
                default => "UNKNOWN({$stat->status})"
            };

            $this->line("    {$statusName}: {$stat->count}");
            if ($stat->status == 1 && $stat->oldest_started) {
                $minutesOld = now()->diffInMinutes($stat->oldest_started);
                $this->line("      Mais antigo: {$minutesOld} minutos");
            }
        }

        // Integrations stuck
        $stuckIntegrations = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
            ->where('started_at', '<', now()->subHours(2))
            ->count();

        if ($stuckIntegrations > 0) {
            $this->warn("    ⚠️ Integrações travadas (>2h): {$stuckIntegrations}");
        }

        $this->newLine();
    }

    /**
     * Limpar slots Redis
     */
    private function cleanRedisSlots(bool $isDryRun): void
    {
        $this->info("🧹 Limpando slots Redis...");

        if (!$isDryRun) {
            try {
                $redis = app('redis')->connection();

                $activeCount = $redis->scard('imovelguide_database_active_integrations');
                $counter = $redis->get('imovelguide_database_active_integrations_count') ?: 0;

                $redis->del('imovelguide_database_active_integrations');
                $redis->del('imovelguide_database_active_integrations_count');

                $this->line("  ✅ Removidos {$activeCount} slots ativos");
                $this->line("  ✅ Contador resetado de {$counter} para 0");

            } catch (\Exception $e) {
                $this->error("  ❌ Erro ao limpar Redis: " . $e->getMessage());
            }
        } else {
            $this->line("  🧪 [DRY-RUN] Limparia slots Redis");
        }
    }

    /**
     * Reset database
     */
    private function resetDatabase(bool $isDryRun): void
    {
        $this->info("🔄 Resetando banco de dados...");

        if (!$isDryRun) {
            try {
                DB::transaction(function() {
                    // Reset queues travadas
                    $stuckCount = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                        ->update([
                            'status' => IntegrationsQueues::STATUS_PENDING,
                            'started_at' => null,
                            'updated_at' => now(),
                            'error_message' => 'Reset pelo comando clean-system'
                        ]);

                    $this->line("  ✅ {$stuckCount} filas resetadas para PENDING");

                    // Reset integrações XML travadas
                    $xmlCount = Integracao::whereIn('status', [6, 7, 8])
                        ->update(['status' => 3]); // XML_STATUS_IN_ANALYSIS

                    $this->line("  ✅ {$xmlCount} integrações XML resetadas");

                    // Limpar tentativas excessivas
                    $retriesCount = IntegrationsQueues::where('attempts', '>', 5)
                        ->update(['attempts' => 0]);

                    if ($retriesCount > 0) {
                        $this->line("  ✅ {$retriesCount} contadores de tentativas resetados");
                    }
                });

            } catch (\Exception $e) {
                $this->error("  ❌ Erro ao resetar database: " . $e->getMessage());
            }
        } else {
            $pendingCount = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count();
            $xmlCount = Integracao::whereIn('status', [6, 7, 8])->count();

            $this->line("  🧪 [DRY-RUN] Resetaria {$pendingCount} filas");
            $this->line("  🧪 [DRY-RUN] Resetaria {$xmlCount} integrações XML");
        }
    }

    /**
     * Verificação final
     */
    private function finalVerification(): void
    {
        $this->info("🔍 Verificação final...");

        $stats = $this->slotManager->getSlotStats();
        $pendingQueues = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_PENDING)->count();
        $processingQueues = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count();

        $this->line("  Redis slots ativos: {$stats['active_slots']}");
        $this->line("  Filas pendentes: {$pendingQueues}");
        $this->line("  Filas processando: {$processingQueues}");

        if ($stats['active_slots'] == 0 && $processingQueues == 0) {
            $this->line("  ✅ Sistema completamente limpo!");
        } elseif ($processingQueues > 0) {
            $this->warn("  ⚠️ Ainda há {$processingQueues} integrações processando");
        }
    }
}