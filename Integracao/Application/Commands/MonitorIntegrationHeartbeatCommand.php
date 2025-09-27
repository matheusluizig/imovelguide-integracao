<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use App\Integracao\Application\Services\IntegrationHeartbeat;

class MonitorIntegrationHeartbeatCommand extends Command
{
    protected $signature = 'integration:monitor-heartbeat 
                            {--continuous : Executar continuamente a cada 30 segundos}
                            {--interval=30 : Intervalo em segundos para modo contínuo}';

    protected $description = 'Monitora heartbeats das integrações e faz auto-reset de integrações travadas';

    public function handle(): int
    {
        $continuous = $this->option('continuous');
        $interval = (int) $this->option('interval');

        $this->info('💓 MONITOR DE HEARTBEAT INICIADO');

        if ($continuous) {
            $this->info("🔄 Modo contínuo ativo (intervalo: {$interval}s)");
            $this->runContinuous($interval);
        } else {
            $this->runOnce();
        }

        return 0;
    }

    private function runOnce(): void
    {
        $heartbeat = app(IntegrationHeartbeat::class);

        try {
            // 1. Limpar heartbeats expirados
            $cleaned = $heartbeat->cleanupExpiredHeartbeats();
            if ($cleaned > 0) {
                $this->info("🧹 Limpou {$cleaned} heartbeats expirados");
            }

            // 2. Verificar integrações travadas
            $stuckIntegrations = $heartbeat->checkStuckIntegrations();

            if (empty($stuckIntegrations)) {
                $this->info('✅ Nenhuma integração travada detectada');
            } else {
                $this->warn("🚨 {count($stuckIntegrations)} integrações travadas detectadas e resetadas");

                foreach ($stuckIntegrations as $stuck) {
                    $this->line("  → ID {$stuck['integration_id']}: presa por {$stuck['stuck_for_seconds']}s (step: {$stuck['current_step']})");
                }
            }

            // 3. Mostrar heartbeats ativos
            $activeHeartbeats = $heartbeat->getActiveHeartbeats();
            if (!empty($activeHeartbeats)) {
                $this->info("💓 {count($activeHeartbeats)} integrações com heartbeat ativo:");

                foreach ($activeHeartbeats as $hb) {
                    $lastHeartbeat = $hb['seconds_since_last_heartbeat'];
                    $status = $lastHeartbeat > 120 ? '🔴' : ($lastHeartbeat > 60 ? '🟡' : '🟢');

                    $this->line("  {$status} ID {$hb['integration_id']}: {$hb['current_step']} (último heartbeat: {$lastHeartbeat}s atrás)");
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Erro durante monitoramento: {$e->getMessage()}");
        }
    }

    private function runContinuous(int $interval): void
    {
        $this->info('🔄 Pressione Ctrl+C para parar...');

        while (true) {
            $startTime = microtime(true);

            $this->line("\n" . str_repeat('=', 60));
            $this->info('🕐 ' . now()->format('Y-m-d H:i:s') . ' - Verificando heartbeats...');

            $this->runOnce();

            $executionTime = microtime(true) - $startTime;
            $sleepTime = max(1, $interval - $executionTime);

            $this->info("⏰ Próxima verificação em {$sleepTime}s...");

            sleep((int) $sleepTime);
        }
    }
}
