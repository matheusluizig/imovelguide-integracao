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
        $this->info('🏥 Verificando saúde do sistema...');

        // Database
        try {
            $integrationCount = Integracao::count();
            $queueCount = IntegrationsQueues::count();
            $this->info("✅ Database: {$integrationCount} integrações, {$queueCount} filas");
        } catch (\Exception $e) {
            $this->error("❌ Database error: " . $e->getMessage());
        }

        // Redis
        try {
            $redis = Redis::connection();
            $redis->ping();
            $this->info("✅ Redis: Conectado");
        } catch (\Exception $e) {
            $this->error("❌ Redis error: " . $e->getMessage());
        }

        // Queue jobs
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            $this->info("✅ Queue: {$pendingJobs} jobs pendentes, {$failedJobs} jobs falhados");
        } catch (\Exception $e) {
            $this->error("❌ Queue error: " . $e->getMessage());
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
