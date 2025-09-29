<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Integracao\Domain\Entities\IntegrationsQueues;

class BreakIntegrationLoopsCommand extends Command
{
    protected $signature = 'integration:break-loops 
                            {--dry-run : Apenas mostrar problemas sem corrigir}
                            {--force : Forçar quebra de loops sem confirmação}';

    protected $description = 'Detecta e quebra loops infinitos em integrações';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔄 DETECTANDO LOOPS INFINITOS EM INTEGRAÇÕES');
        $this->newLine();

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: Nenhuma alteração será feita');
        }

        $loops = $this->detectLoops();

        if (empty($loops)) {
            $this->info('✅ Nenhum loop infinito detectado');
            return 0;
        }

        $this->error("❌ Detectados {$loops['count']} possíveis loops infinitos:");
        $this->displayLoops($loops['items']);

        if (!$dryRun && ($force || $this->confirm('Deseja quebrar estes loops?'))) {
            return $this->breakLoops($loops['items']);
        }

        return 0;
    }

    private function detectLoops(): array
    {
        try {
            // Detectar integrações que estão há muito tempo em IN_PROCESS sem atividade
            $suspiciousIntegrations = IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->where('started_at', '<', now()->subMinutes(30))
                ->get();

            $loops = [];
            foreach ($suspiciousIntegrations as $queue) {
                // Verificar atividade recente
                $hasActivity = $this->hasRecentActivity($queue->integration_id);

                // Verificar jobs na fila Redis
                $hasQueuedJobs = $this->hasJobsInRedisQueue($queue->integration_id);

                if (!$hasActivity && $hasQueuedJobs) {
                    $loops[] = [
                        'integration_id' => $queue->integration_id,
                        'queue_id' => $queue->id,
                        'started_at' => $queue->started_at,
                        'minutes_stuck' => $queue->started_at ? now()->diffInMinutes($queue->started_at) : 0,
                        'attempts' => $queue->attempts ?? 0,
                        'has_activity' => $hasActivity,
                        'has_queued_jobs' => $hasQueuedJobs
                    ];
                }
            }

            return [
                'count' => count($loops),
                'items' => $loops
            ];

        } catch (\Exception $e) {
            $this->error("Erro ao detectar loops: " . $e->getMessage());
            return ['count' => 0, 'items' => []];
        }
    }

    private function hasRecentActivity(int $integrationId): bool
    {
        try {
            return DB::table('anuncios')
                ->where('integration_id', $integrationId)
                ->where('updated_at', '>=', now()->subMinutes(15))
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function hasJobsInRedisQueue(int $integrationId): bool
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $queues = ['priority-integrations', 'level-integrations', 'normal-integrations'];

            foreach ($queues as $queueName) {
                $jobs = $redis->lrange("queues:{$queueName}", 0, -1);

                foreach ($jobs as $job) {
                    $payload = json_decode($job, true);
                    $jobIntegrationId = $payload['data']['integrationId'] ?? null;

                    if ($jobIntegrationId == $integrationId) {
                        return true;
                    }
                }
            }

            return false;

        } catch (\Exception $e) {
            return false;
        }
    }

    private function displayLoops(array $loops): void
    {
        $headers = ['ID Int.', 'Queue ID', 'Preso há', 'Tentativas', 'Atividade', 'Jobs na Fila'];

        $rows = array_map(function ($loop) {
            return [
                $loop['integration_id'],
                $loop['queue_id'],
                $loop['minutes_stuck'] . 'min',
                $loop['attempts'],
                $loop['has_activity'] ? '🟢 Sim' : '🔴 Não',
                $loop['has_queued_jobs'] ? '🟢 Sim' : '🔴 Não'
            ];
        }, $loops);

        $this->table($headers, $rows);
    }

    private function breakLoops(array $loops): int
    {
        $broken = 0;
        $errors = 0;

        foreach ($loops as $loop) {
            try {
                DB::transaction(function () use ($loop, &$broken) {
                    // Reset do status da queue
                    $queue = IntegrationsQueues::find($loop['queue_id']);
                    if ($queue) {
                        $queue->update([
                            'status' => IntegrationsQueues::STATUS_STOPPED,
                            'ended_at' => now(),
                            'error_message' => 'Loop infinito detectado e quebrado automaticamente',
                            'last_error_step' => 'loop_breaker',
                            'updated_at' => now()
                        ]);
                    }

                    // Remover jobs da fila Redis
                    $this->removeJobsFromRedis($loop['integration_id']);

                    $broken++;
                });

                Log::channel('integration')->warning("Loop infinito quebrado", [
                    'integration_id' => $loop['integration_id'],
                    'queue_id' => $loop['queue_id'],
                    'minutes_stuck' => $loop['minutes_stuck']
                ]);

                $this->info("✅ Loop quebrado para integração {$loop['integration_id']}");

            } catch (\Exception $e) {
                $this->error("❌ Erro ao quebrar loop da integração {$loop['integration_id']}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("🎉 Loops quebrados: {$broken}");
        if ($errors > 0) {
            $this->error("❌ Erros: {$errors}");
        }

        return $errors > 0 ? 1 : 0;
    }

    private function removeJobsFromRedis(int $integrationId): void
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $queues = ['priority-integrations', 'level-integrations', 'normal-integrations'];

            foreach ($queues as $queueName) {
                $queueKey = "queues:{$queueName}";
                $jobs = $redis->lrange($queueKey, 0, -1);

                foreach ($jobs as $index => $job) {
                    $payload = json_decode($job, true);
                    $jobIntegrationId = $payload['data']['integrationId'] ?? null;

                    if ($jobIntegrationId == $integrationId) {
                        // Remove o job específico da fila
                        $redis->lrem($queueKey, 1, $job);
                        $this->line("  Removido job da fila {$queueName}");
                    }
                }
            }

        } catch (\Exception $e) {
            Log::channel('integration')->error("Failed to remove jobs from Redis", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
        }
    }
}