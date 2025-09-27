<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class DiagnoseIntegrationJobs extends Command
{
    protected $signature = 'integration:diagnose 
                            {--integration-id= : ID específico da integração para diagnosticar}
                            {--check-redis : Verificar estado do Redis}
                            {--check-failed : Verificar jobs falhados recentes}
                            {--test-job : Executar job de teste}
                            {--clear-locks : Limpar locks de cache}';

    protected $description = 'Diagnostica problemas com jobs de integração que falham imediatamente';

    public function handle(): int
    {
        $this->info('🔍 DIAGNÓSTICO DE JOBS DE INTEGRAÇÃO');
        $this->newLine();

        if ($this->option('integration-id')) {
            return $this->diagnoseSpecificIntegration((int) $this->option('integration-id'));
        }

        $this->checkSystemHealth();

        if ($this->option('check-redis')) {
            $this->checkRedisState();
        }

        if ($this->option('check-failed')) {
            $this->checkFailedJobs();
        }

        if ($this->option('test-job')) {
            $this->testJobExecution();
        }

        if ($this->option('clear-locks')) {
            $this->clearCacheLocks();
        }

        return 0;
    }

    private function diagnoseSpecificIntegration(int $integrationId): int
    {
        $this->info("🔎 Diagnosticando integração ID: {$integrationId}");
        $this->newLine();

        // 1. Verificar se integração existe
        $integration = Integracao::with(['user'])->find($integrationId);
        if (!$integration) {
            $this->error("❌ Integração não encontrada no banco de dados");
            return 1;
        }

        $this->info("✅ Integração encontrada:");
        $this->table(['Campo', 'Valor'], [
            ['ID', $integration->id],
            ['User ID', $integration->user_id],
            ['Link', $integration->link],
            ['Status', $integration->status],
            ['Sistema', $integration->system ?? 'N/A'],
            ['Criada em', $integration->created_at],
            ['Atualizada em', $integration->updated_at]
        ]);

        // 2. Verificar usuário
        if (!$integration->user) {
            $this->error("❌ Usuário não encontrado para a integração");
            return 1;
        }

        $this->info("✅ Usuário encontrado:");
        $this->table(['Campo', 'Valor'], [
            ['ID', $integration->user->id],
            ['Nome', $integration->user->name],
            ['Email', $integration->user->email],
            ['Inativo', $integration->user->inative ? 'Sim' : 'Não'],
            ['Level', $integration->user->level ?? 'N/A'],
            ['Prioridade Integração', $integration->user->integration_priority ?? 'N/A']
        ]);

        if ($integration->user->inative) {
            $this->warn("⚠️  Usuário está inativo - isso pode causar falhas");
        }

        // 3. Verificar fila
        $queue = IntegrationsQueues::where('integration_id', $integrationId)->first();
        if (!$queue) {
            $this->error("❌ Fila não encontrada para a integração");
            return 1;
        }

        $this->info("✅ Fila encontrada:");
        $this->table(['Campo', 'Valor'], [
            ['ID', $queue->id],
            ['Status', $this->getQueueStatusName($queue->status)],
            ['Prioridade', $this->getPriorityName($queue->priority)],
            ['Iniciada em', $queue->started_at ?? 'N/A'],
            ['Finalizada em', $queue->ended_at ?? 'N/A'],
            ['Tempo execução', $queue->execution_time ?? 'N/A'],
            ['Tentativas', $queue->attempts ?? 0],
            ['Última mensagem erro', $queue->error_message ?? 'N/A'],
            ['Último passo erro', $queue->last_error_step ?? 'N/A']
        ]);

        // 4. Verificar URL
        $this->info("🌐 Verificando URL da integração...");
        if (empty($integration->link)) {
            $this->error("❌ URL da integração está vazia");
            return 1;
        }

        if (!filter_var($integration->link, FILTER_VALIDATE_URL)) {
            $this->error("❌ URL da integração é inválida: {$integration->link}");
            return 1;
        }

        $this->info("✅ URL válida: {$integration->link}");

        // 5. Testar conectividade
        $this->info("🔗 Testando conectividade com a URL...");
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'method' => 'HEAD'
                ]
            ]);

            $headers = @get_headers($integration->link, 1, $context);
            if ($headers === false) {
                $this->error("❌ Não foi possível conectar à URL");
            } else {
                $statusLine = $headers[0] ?? 'Unknown';
                $this->info("✅ Conectividade OK: {$statusLine}");
            }
        } catch (\Exception $e) {
            $this->warn("⚠️  Erro ao testar conectividade: " . $e->getMessage());
        }

        // 6. Verificar Redis slots
        $this->info("🔴 Verificando slots Redis...");
        try {
            $redis = Redis::connection();
            $activeSlots = $redis->smembers('imovelguide_database_active_integrations');
            $slotsCount = (int) ($redis->get('imovelguide_database_active_integrations_count') ?: 0);

            $this->info("✅ Slots Redis:");
            $this->info("   - Slots ativos: {$slotsCount}");
            $this->info("   - Integrações ativas: " . implode(', ', $activeSlots ?: ['Nenhuma']));

            if (in_array($integrationId, $activeSlots)) {
                $this->warn("⚠️  Esta integração já está ativa no Redis");
            }
        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar Redis: " . $e->getMessage());
        }

        // 7. Verificar logs recentes
        $this->info("📝 Verificando logs recentes...");
        $this->checkRecentLogs($integrationId);

        // 8. Teste de job
        if ($this->confirm('Deseja executar um teste do job para esta integração?')) {
            $this->testSpecificJob($integrationId);
        }

        return 0;
    }

    private function checkSystemHealth(): void
    {
        $this->info('🏥 DIAGNÓSTICO COMPLETO DO SISTEMA');
        $this->newLine();

        $this->checkDatabaseHealth();
        $this->checkRedisHealth();
        $this->checkActiveWorkers();
        $this->checkQueueStatus();
        $this->checkIntegrationProgress();
        $this->showNext10InQueue();
    }

    private function checkDatabaseHealth(): void
    {
        $this->info('💾 STATUS DO BANCO DE DADOS');

        try {
            $stats = [
                'Total Integrações' => Integracao::count(),
                'Integrações Ativas' => Integracao::whereHas('user', fn($q) => $q->where('inative', 0))->count(),
                'Filas Pendentes' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_PENDING)->count(),
                'Filas Processando' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count(),
                'Filas com Erro' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_ERROR)->count(),
                'Filas Paradas' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_STOPPED)->count(),
                'Concluídas Hoje' => IntegrationsQueues::where('status', IntegrationsQueues::STATUS_DONE)
                    ->whereDate('completed_at', today())->count()
            ];

            $rows = array_map(fn($key, $value) => [$key, $value], array_keys($stats), array_values($stats));
            $this->table(['Métrica', 'Valor'], $rows);

        } catch (\Exception $e) {
            $this->error("❌ Erro no banco: " . $e->getMessage());
        }
    }

    private function checkRedisHealth(): void
    {
        $this->info('🔴 STATUS DO REDIS');

        try {
            $redis = Redis::connection();
            $info = $redis->info();

            $stats = [
                'Conectividade' => 'OK',
                'Versão' => $info['redis_version'] ?? 'N/A',
                'Uptime' => gmdate('H:i:s', $info['uptime_in_seconds'] ?? 0),
                'Memória Usada' => $this->formatBytes($info['used_memory'] ?? 0),
                'Conexões' => $info['connected_clients'] ?? 0,
                'Total Comandos' => number_format($info['total_commands_processed'] ?? 0)
            ];

            $rows = array_map(fn($key, $value) => [$key, $value], array_keys($stats), array_values($stats));
            $this->table(['Métrica', 'Valor'], $rows);

        } catch (\Exception $e) {
            $this->error("❌ Redis indisponível: " . $e->getMessage());
        }
    }

    private function checkActiveWorkers(): void
    {
        $this->info('👷 WORKERS ATIVOS');

        try {
            $redis = Redis::connection();

            $integrationQueues = [
                'priority-integrations' => 'Planos',
                'level-integrations' => 'Níveis',
                'normal-integrations' => 'Normais'
            ];

            $totalWorkers = 0;
            $workerDetails = [];

            foreach ($integrationQueues as $queueName => $description) {
                $queueLength = $redis->llen("queues:{$queueName}");
                $processingCount = $this->getProcessingJobsCount($queueName);
                $totalWorkers += $processingCount;

                $workerDetails[] = [
                    $description,
                    $processingCount,
                    $queueLength,
                    $processingCount > 0 ? '🟢 Ativo' : ($queueLength > 0 ? '🟡 Aguardando' : '⚫ Ocioso')
                ];
            }

            $this->table(['Fila', 'Workers Ativos', 'Jobs na Fila', 'Status'], $workerDetails);
            $this->info("📊 Total de workers ativos: {$totalWorkers}");

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar workers: " . $e->getMessage());
        }
    }

    private function getProcessingJobsCount(string $queueName): int
    {
        try {
            // Para filas Redis, verificar processos ativos do queue:work
            $command = "ps aux | grep 'queue:work.*{$queueName}' | grep -v grep | wc -l";
            $result = shell_exec($command);
            return (int) trim($result);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function checkQueueStatus(): void
    {
        $this->info('📋 STATUS DAS FILAS (DADOS REAIS DO REDIS)');

        try {
            $redis = Redis::connection();

            $queueStats = [];
            $integrationQueues = [
                'priority-integrations' => 'Planos',
                'level-integrations' => 'Níveis',
                'normal-integrations' => 'Normais',
                'default' => 'Padrão'
            ];

            foreach ($integrationQueues as $queueName => $description) {
                $waiting = $redis->llen("queues:{$queueName}");
                $delayed = $redis->zcard("queues:{$queueName}:delayed");
                $processing = $this->getProcessingJobsCount($queueName);
                $failed = $this->getFailedJobsCount($queueName);

                $queueStats[] = [
                    $description,
                    $waiting,
                    $processing,
                    $delayed,
                    $failed,
                    $processing > 0 ? '🔄' : ($waiting > 0 ? '⏳' : '✅')
                ];
            }

            $this->table([
                'Fila',
                'Aguardando',
                'Processando',
                'Atrasados',
                'Falhados',
                'Status'
            ], $queueStats);

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar filas: " . $e->getMessage());
        }
    }

    private function getFailedJobsCount(string $queueName): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('queue', $queueName)
                ->whereDate('failed_at', today())
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function checkIntegrationProgress(): void
    {
        $this->info('📊 INTEGRAÇÕES EM PROCESSAMENTO');

        try {
            $processing = IntegrationsQueues::with(['integracaoXml.user'])
                ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->where('started_at', '>', now()->subHours(2))
                ->get();

            if ($processing->isEmpty()) {
                $this->info('✅ Nenhuma integração sendo processada no momento');
                return;
            }

            $progressData = [];
            foreach ($processing as $queue) {
                $integration = $queue->integracaoXml;
                if (!$integration) { continue;
                }

                $duration = $queue->started_at ? $queue->started_at->diffInMinutes(now()) : 0;
                $progress = $this->estimateProgress($queue, $duration);

                $progressData[] = [
                    $integration->id,
                    substr($integration->user->name ?? 'N/A', 0, 20),
                    $this->getPriorityName($queue->priority),
                    $duration . 'min',
                    $progress . '%',
                    $queue->attempts ?? 1
                ];
            }

            $this->table([
                'ID Int.',
                'Usuário',
                'Prioridade',
                'Tempo',
                'Progresso Est.',
                'Tentativa'
            ], $progressData);

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar progresso: " . $e->getMessage());
        }
    }

    private function estimateProgress(IntegrationsQueues $queue, int $durationMinutes): int
    {
        $avgProcessingTime = 5;
        if ($durationMinutes >= $avgProcessingTime) {
            return 95;
        }

        return min(90, ($durationMinutes / $avgProcessingTime) * 100);
    }

    private function showNext10InQueue(): void
    {
        $this->info('🔮 PRÓXIMAS 10 INTEGRAÇÕES NA FILA (DADOS REAIS DO REDIS)');

        try {
            $redis = Redis::connection();
            $queueData = [];

            $integrationQueues = [
                'priority-integrations' => 'Planos',
                'level-integrations' => 'Níveis',
                'normal-integrations' => 'Normais'
            ];

            foreach ($integrationQueues as $queueName => $description) {
                $jobs = $redis->lrange("queues:{$queueName}", 0, 9);

                foreach ($jobs as $index => $job) {
                    $payload = json_decode($job, true);
                    $integrationId = $payload['data']['integrationId'] ?? 'N/A';

                    if ($integrationId !== 'N/A') {
                        $integration = Integracao::with('user')->find($integrationId);
                        $userName = $integration->user->name ?? 'N/A';
                    } else {
                        $userName = 'N/A';
                    }

                    $queueData[] = [
                        $index + 1,
                        $description,
                        $integrationId,
                        substr($userName, 0, 20),
                        isset($payload['attempts']) ? $payload['attempts'] : 0,
                        $payload['id'] ?? 'N/A'
                    ];

                    if (count($queueData) >= 10) { break 2;
                    }
                }
            }

            if (empty($queueData)) {
                $this->info('✅ Nenhum job na fila no momento');
                return;
            }

            $this->table([
                'Pos.',
                'Fila',
                'ID Int.',
                'Usuário',
                'Tentativas',
                'Job ID'
            ], $queueData);

        } catch (\Exception $e) {
            $this->error("❌ Erro ao verificar próximos jobs: " . $e->getMessage());
        }
    }

    private function checkRedisState(): void
    {
        $this->info('🔴 Estado detalhado do Redis...');

        try {
            $redis = Redis::connection();

            $activeSlots = $redis->smembers('imovelguide_database_active_integrations');
            $slotsCount = (int) ($redis->get('imovelguide_database_active_integrations_count') ?: 0);

            $this->table(['Métrica', 'Valor'], [
                ['Slots ativos (contador)', $slotsCount],
                ['Slots ativos (set)', count($activeSlots)],
                ['Integrações no set', implode(', ', $activeSlots ?: ['Nenhuma'])],
                ['Inconsistência', $slotsCount !== count($activeSlots) ? 'SIM' : 'NÃO']
            ]);

            if ($slotsCount !== count($activeSlots)) {
                $this->warn('⚠️  Inconsistência detectada entre contador e set Redis!');

                if ($this->confirm('Deseja corrigir a inconsistência?')) {
                    $redis->set('imovelguide_database_active_integrations_count', count($activeSlots));
                    $this->info('✅ Inconsistência corrigida');
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Erro Redis: " . $e->getMessage());
        }
    }

    private function checkFailedJobs(): void
    {
        $this->info('💥 Verificando jobs falhados recentes...');

        $failedJobs = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHours(24))
            ->orderBy('failed_at', 'desc')
            ->limit(10)
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->info('✅ Nenhum job falhado nas últimas 24 horas');
            return;
        }

        foreach ($failedJobs as $job) {
            $payload = json_decode($job->payload, true);
            $exception = json_decode($job->exception, true);

            $this->warn("❌ Job falhado: {$job->failed_at}");
            $this->info("   Queue: {$job->queue}");
            $this->info("   Class: " . ($payload['displayName'] ?? 'Unknown'));
            $this->info("   Error: " . substr($exception['message'] ?? 'Unknown', 0, 100));
            $this->newLine();
        }
    }

    private function testJobExecution(): void
    {
        $this->info('🧪 Executando teste de job...');

        // Find a test integration
        $integration = Integracao::with(['user'])
            ->whereHas('user', function($q) {
                $q->where('inative', 0);
            })
            ->first();

        if (!$integration) {
            $this->error('❌ Nenhuma integração ativa encontrada para teste');
            return;
        }

        $this->info("Testando com integração ID: {$integration->id}");

        try {
            $job = new ProcessIntegrationJob($integration->id);

            $startTime = microtime(true);
            $job->handle();
            $endTime = microtime(true);

            $this->info("✅ Job executado com sucesso em " . number_format($endTime - $startTime, 3) . 's');

        } catch (\Exception $e) {
            $this->error("❌ Job falhou: " . $e->getMessage());
            $this->info("Arquivo: " . $e->getFile() . ':' . $e->getLine());
        }
    }

    private function testSpecificJob(int $integrationId): void
    {
        $this->info("🧪 Executando teste específico para integração {$integrationId}...");

        try {
            $job = new ProcessIntegrationJob($integrationId);

            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $job->handle();

            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);

            $this->info("✅ Job executado com sucesso:");
            $this->info("   - Tempo: " . number_format($endTime - $startTime, 3) . 's');
            $this->info("   - Memória: " . $this->formatBytes($endMemory - $startMemory));

        } catch (\Exception $e) {
            $this->error("❌ Job falhou: " . $e->getMessage());
            $this->error("   - Arquivo: " . $e->getFile() . ':' . $e->getLine());
            $this->error("   - Tipo: " . get_class($e));

            if ($this->output->isVerbose()) {
                $this->error("   - Trace:");
                $this->error($e->getTraceAsString());
            }
        }
    }

    private function clearCacheLocks(): void
    {
        $this->info('🧹 Limpando locks de cache...');

        try {
            $redis = Redis::connection();

            // Clear integration processing locks
            $keys = $redis->keys('integration_processing_*');
            if (!empty($keys)) {
                $redis->del($keys);
                $this->info("✅ Removidos " . count($keys) . " locks de processamento");
            }

            // Clear other integration locks
            $keys = $redis->keys('*integration*lock*');
            if (!empty($keys)) {
                $redis->del($keys);
                $this->info("✅ Removidos " . count($keys) . " locks gerais");
            }

        } catch (\Exception $e) {
            $this->error("❌ Erro ao limpar locks: " . $e->getMessage());
        }
    }

    private function checkRecentLogs(int $integrationId): void
    {
        $logFile = storage_path('logs/laravel.log');
        if (!file_exists($logFile)) {
            $this->warn('⚠️  Arquivo de log não encontrado');
            return;
        }

        $command = "grep -n \"integration_id.*{$integrationId}\" " . escapeshellarg($logFile) . " | tail -10";
        $output = shell_exec($command);

        if (empty($output)) {
            $this->info('ℹ️  Nenhum log recente encontrado para esta integração');
            return;
        }

        $this->info('📝 Logs recentes:');
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (!empty($line)) {
                $this->info('   ' . substr($line, 0, 150));
            }
        }
    }

    private function getQueueStatusName(int $status): string
    {
        return match($status) {
            IntegrationsQueues::STATUS_PENDING => 'PENDENTE',
            IntegrationsQueues::STATUS_IN_PROCESS => 'EM_PROCESSO',
            IntegrationsQueues::STATUS_DONE => 'CONCLUÍDO',
            IntegrationsQueues::STATUS_STOPPED => 'PARADO',
            IntegrationsQueues::STATUS_ERROR => 'ERRO',
            default => 'DESCONHECIDO'
        };
    }

    private function getPriorityName(int $priority): string
    {
        return match($priority) {
            IntegrationsQueues::PRIORITY_PLAN => 'PLANO',
            IntegrationsQueues::PRIORITY_LEVEL => 'NÍVEL',
            IntegrationsQueues::PRIORITY_NORMAL => 'NORMAL',
            default => 'DESCONHECIDO'
        };
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}