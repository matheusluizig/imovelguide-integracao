<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Application\Services\IntegrationSlotManager;
use App\Integracao\Application\Services\IntegrationHeartbeat;

class ResetStuckIntegrationsCommand extends Command
{
    protected $signature = 'integration:reset-stuck 
                            {--dry-run : Apenas mostrar integrações presas sem resetar}
                            {--force : Forçar reset sem confirmação}
                            {--minutes= : Minutos para considerar preso (padrão: 5)}
                            {--heartbeat-check : Verificar heartbeats para identificar travamentos}
                            {--clean-expired-slots : Limpar slots expirados no Redis}';

    protected $description = 'Detecta e reseta integrações travadas usando múltiplos critérios';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $minutesThreshold = (int) ($this->option('minutes') ?: 5);
        $checkHeartbeat = $this->option('heartbeat-check');
        $cleanSlots = $this->option('clean-expired-slots');

        $this->info('🔍 DETECTANDO INTEGRAÇÕES TRAVADAS');
        $this->newLine();

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: Nenhuma alteração será feita');
        }

        $results = [];

        // 1. Verificar heartbeats se solicitado
        if ($checkHeartbeat) {
            $results['heartbeat'] = $this->checkHeartbeatStuckIntegrations($dryRun);
        }

        // 2. Limpar slots expirados se solicitado
        if ($cleanSlots) {
            $results['expired_slots'] = $this->cleanExpiredSlots($dryRun);
        }

        // 3. Verificar integrações presas por tempo
        $results['time_based'] = $this->checkTimeBasedStuckIntegrations($minutesThreshold, $dryRun, $force);

        // 4. Verificar inconsistências Redis vs DB
        $results['redis_inconsistencies'] = $this->checkRedisDbInconsistencies($dryRun, $force);

        $this->displaySummary($results);

        return 0;
    }

    private function checkHeartbeatStuckIntegrations(bool $dryRun): array
    {
        $this->info('💓 VERIFICANDO HEARTBEATS');

        try {
            $heartbeat = app(IntegrationHeartbeat::class);

            // Limpar heartbeats expirados primeiro
            $cleaned = $heartbeat->cleanupExpiredHeartbeats();
            if ($cleaned > 0) {
                $this->info("🧹 Limpou {$cleaned} heartbeats expirados");
            }

            // Verificar integrações travadas
            if (!$dryRun) {
                $stuckIntegrations = $heartbeat->checkStuckIntegrations();
            } else {
                // No dry-run, apenas listar
                $activeHeartbeats = $heartbeat->getActiveHeartbeats();
                $stuckIntegrations = array_filter($activeHeartbeats, function($hb) {
                    return $hb['seconds_since_last_heartbeat'] > 300; // 5 minutos
                });
            }

            if (empty($stuckIntegrations)) {
                $this->info('✅ Nenhuma integração travada detectada via heartbeat');
                return ['count' => 0, 'items' => []];
            }

            $this->warn("🚨 {count($stuckIntegrations)} integrações travadas detectadas via heartbeat:");

            $tableData = [];
            foreach ($stuckIntegrations as $stuck) {
                $tableData[] = [
                    $stuck['integration_id'],
                    gmdate('H:i:s', $stuck['stuck_for_seconds'] ?? $stuck['seconds_since_last_heartbeat']),
                    $stuck['current_step'] ?? 'unknown',
                    $stuck['worker_pid'] ?? 'N/A',
                    $dryRun ? 'DRY-RUN' : '✅ RESETADA'
                ];
            }

            $this->table([
                'ID Int.',
                'Travada há',
                'Último Step',
                'Worker PID',
                'Status'
            ], $tableData);

            return [
                'count' => count($stuckIntegrations),
                'items' => $stuckIntegrations
            ];

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar heartbeats: {$e->getMessage()}");
            return ['count' => 0, 'items' => [], 'error' => $e->getMessage()];
        }
    }

    private function cleanExpiredSlots(bool $dryRun): array
    {
        $this->info('🧹 LIMPANDO SLOTS EXPIRADOS');

        try {
            $slotManager = app(IntegrationSlotManager::class);
            $redis = app('redis')->connection();

            if (!$dryRun) {
                $slotManager->cleanupExpiredSlots($redis);
                $this->info('✅ Limpeza de slots expirados executada');
            } else {
                $this->info('✅ (DRY-RUN) Limpeza de slots seria executada');
            }

            // Mostrar estatísticas atuais
            $stats = $slotManager->getSlotStats();
            $this->table(['Métrica', 'Valor'], [
                ['Slots ativos', $stats['active_slots'] ?? 0],
                ['Contador', $stats['counter'] ?? 0],
                ['Slots disponíveis', $stats['available_slots'] ?? 3],
                ['IDs ativos', implode(', ', $stats['active_integration_ids'] ?? []) ?: 'Nenhum']
            ]);

            return [
                'executed' => !$dryRun,
                'stats' => $stats
            ];

        } catch (\Exception $e) {
            $this->error("❌ Erro ao limpar slots: {$e->getMessage()}");
            return ['executed' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkTimeBasedStuckIntegrations(int $minutesThreshold, bool $dryRun, bool $force): array
    {
        $this->info("⏰ VERIFICANDO INTEGRAÇÕES PRESAS (>{$minutesThreshold} min)");

        try {
            $stuckIntegrations = IntegrationsQueues::with(['integracaoXml.user'])
                ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->where('started_at', '<', now()->subMinutes($minutesThreshold))
                ->get()
                ->filter(function ($queue) {
                    $integration = $queue->integracaoXml;
                    if (!$integration || !$integration->user_id) {
                        return false;
                    }
                    return !$this->hasRecentActivity($integration->user_id);
                });

            if ($stuckIntegrations->isEmpty()) {
                $this->info('✅ Nenhuma integração presa por tempo encontrada');
                return ['count' => 0, 'items' => []];
            }

            $this->warn("🚨 {$stuckIntegrations->count()} integrações presas encontradas:");

            $tableData = [];
            foreach ($stuckIntegrations as $queue) {
                $integration = $queue->integracaoXml;
                $minutesStuck = $queue->started_at ? max(0, now()->diffInMinutes($queue->started_at)) : 0;

                $tableData[] = [
                    $queue->integration_id,
                    $queue->id,
                    $integration->user->name ?? 'N/A',
                    $minutesStuck . ' min',
                    $queue->attempts ?? 0,
                    $queue->error_message ? substr($queue->error_message, 0, 30) . '...' : 'N/A'
                ];
            }

            $this->table([
                'ID Int.',
                'Queue ID',
                'Usuário',
                'Presa há',
                'Tentativas',
                'Último Erro'
            ], $tableData);

            if (!$dryRun && ($force || $this->confirm('Deseja resetar essas integrações?'))) {
                $resetCount = $this->resetStuckIntegrations($stuckIntegrations);
                $this->info("✅ {$resetCount} integrações resetadas com sucesso");

                return [
                    'count' => $stuckIntegrations->count(),
                    'reset_count' => $resetCount,
                    'items' => $stuckIntegrations->toArray()
                ];
            }

            return [
                'count' => $stuckIntegrations->count(),
                'reset_count' => 0,
                'items' => $stuckIntegrations->toArray()
            ];

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar integrações presas: {$e->getMessage()}");
            return ['count' => 0, 'reset_count' => 0, 'error' => $e->getMessage()];
        }
    }

    private function hasRecentActivity(int $userId): bool
    {
        try {
            return \DB::table('anuncios as A')
                ->where('A.user_id', $userId)
                ->where('A.updated_at', '>=', now()->subMinutes(10))
                ->exists();
        } catch (\Exception $e) {
            return true;
        }
    }

    private function checkRedisDbInconsistencies(bool $dryRun, bool $force): array
    {
        $this->info('🔄 VERIFICANDO INCONSISTÊNCIAS REDIS vs DB');

        try {
            $redis = app('redis')->connection();
            $slotManager = app(IntegrationSlotManager::class);

            // Obter dados Redis
            $activeInRedis = $redis->smembers('imovelguide_database_active_integrations');
            $counterInRedis = (int) ($redis->get('imovelguide_database_active_integrations_count') ?: 0);

            // Obter dados DB
            $processingInDb = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->pluck('integration_id')
                ->toArray();

            // Encontrar inconsistências
            $onlyInRedis = array_diff($activeInRedis, $processingInDb);
            $onlyInDb = array_diff($processingInDb, $activeInRedis);
            $counterMismatch = $counterInRedis !== count($activeInRedis);

            $inconsistencies = [];

            if (!empty($onlyInRedis)) {
                $inconsistencies['only_in_redis'] = $onlyInRedis;
                $this->warn("⚠️  IDs apenas no Redis: " . implode(', ', $onlyInRedis));
            }

            if (!empty($onlyInDb)) {
                $inconsistencies['only_in_db'] = $onlyInDb;
                $this->warn("⚠️  IDs apenas no DB: " . implode(', ', $onlyInDb));
            }

            if ($counterMismatch) {
                $inconsistencies['counter_mismatch'] = [
                    'redis_counter' => $counterInRedis,
                    'redis_set_count' => count($activeInRedis)
                ];
                $this->warn("⚠️  Contador Redis ({$counterInRedis}) ≠ Set count (" . count($activeInRedis) . ")");
            }

            if (empty($inconsistencies)) {
                $this->info('✅ Nenhuma inconsistência encontrada');
                return ['inconsistencies' => [], 'fixed' => false];
            }

            if (!$dryRun && ($force || $this->confirm('Deseja corrigir essas inconsistências?'))) {
                $this->fixRedisDbInconsistencies($inconsistencies, $redis, $slotManager);
                $this->info('✅ Inconsistências corrigidas');

                return ['inconsistencies' => $inconsistencies, 'fixed' => true];
            }

            return ['inconsistencies' => $inconsistencies, 'fixed' => false];

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar inconsistências: {$e->getMessage()}");
            return ['inconsistencies' => [], 'fixed' => false, 'error' => $e->getMessage()];
        }
    }

    private function resetStuckIntegrations($stuckIntegrations): int
    {
        $resetCount = 0;
        $slotManager = app(IntegrationSlotManager::class);
        $statusManager = app(\App\Integracao\Application\Services\IntegrationStatusManager::class);

        foreach ($stuckIntegrations as $queue) {
            try {
                DB::transaction(function() use ($queue, $statusManager) {
                    // Reset completo da queue usando o StatusManager (zera TODOS os campos)
                    $statusManager->resetToPending($queue, 'Reset automático: integração presa por tempo');

                    // Incrementar tentativas mantendo histórico
                    $queue->update(['attempts' => ($queue->attempts ?? 0) + 1]);

                    // Reset integração se necessário
                    $integration = Integracao::find($queue->integration_id);
                    if ($integration && in_array($integration->status, [6, 7, 8])) {
                        $integration->update(['status' => 3]); // XML_STATUS_IN_ANALYSIS
                    }
                });

                // Liberar slot Redis
                $slotManager->releaseSlot($queue->integration_id);

                Log::channel('integration')->info('Integration auto-reset completed with full queue reset', [
                    'integration_id' => $queue->integration_id,
                    'queue_id' => $queue->id,
                    'reset_reason' => 'stuck_by_time',
                    'attempts' => $queue->attempts ?? 0
                ]);

                $resetCount++;

            } catch (\Exception $e) {
                $this->error("❌ Falha ao resetar integração {$queue->integration_id}: {$e->getMessage()}");
            }
        }

        return $resetCount;
    }

    private function fixRedisDbInconsistencies(array $inconsistencies, $redis, IntegrationSlotManager $slotManager): void
    {
        // Corrigir IDs apenas no Redis (remover)
        if (isset($inconsistencies['only_in_redis'])) {
            foreach ($inconsistencies['only_in_redis'] as $integrationId) {
                $slotManager->releaseSlot($integrationId);
            }
        }

        // Corrigir contador desalinhado
        if (isset($inconsistencies['counter_mismatch'])) {
            $actualCount = count($redis->smembers('imovelguide_database_active_integrations'));
            $redis->set('imovelguide_database_active_integrations_count', $actualCount);
        }

        // IDs apenas no DB não precisam correção no Redis - são válidos
    }

    private function displaySummary(array $results): void
    {
        $this->newLine();
        $this->info('📊 RESUMO DA EXECUÇÃO');

        $summaryData = [];

        if (isset($results['heartbeat'])) {
            $summaryData[] = [
                'Heartbeat Check',
                $results['heartbeat']['count'] ?? 0,
                isset($results['heartbeat']['error']) ? '❌ Erro' : '✅ OK'
            ];
        }

        if (isset($results['expired_slots'])) {
            $summaryData[] = [
                'Limpeza Slots',
                $results['expired_slots']['executed'] ? 1 : 0,
                isset($results['expired_slots']['error']) ? '❌ Erro' : '✅ OK'
            ];
        }

        if (isset($results['time_based'])) {
            $summaryData[] = [
                'Reset por Tempo',
                $results['time_based']['reset_count'] ?? 0,
                isset($results['time_based']['error']) ? '❌ Erro' : '✅ OK'
            ];
        }

        if (isset($results['redis_inconsistencies'])) {
            $inconsistencyCount = count($results['redis_inconsistencies']['inconsistencies'] ?? []);
            $summaryData[] = [
                'Inconsistências',
                $inconsistencyCount,
                $results['redis_inconsistencies']['fixed'] ? '✅ Corrigidas' : ($inconsistencyCount > 0 ? '⚠️  Encontradas' : '✅ OK')
            ];
        }

        if (!empty($summaryData)) {
            $this->table(['Verificação', 'Quantidade', 'Status'], $summaryData);
        }
    }
}
