<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class IntegrationLogAnalyzer extends Command
{
    protected $signature = 'integration:logs 
                            {action : analyze|errors|performance|correlation}
                            {--correlation-id= : Correlation ID para análise específica}
                            {--integration-id= : Integration ID para análise}
                            {--period=24 : Período em horas}
                            {--limit=100 : Limite de resultados}';

    protected $description = 'Analisa logs do sistema de integração';

    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'analyze':
                $this->analyzeLogs();
                break;
            case 'errors':
                $this->analyzeErrors();
                break;
            case 'performance':
                $this->analyzePerformance();
                break;
            case 'correlation':
                $this->analyzeCorrelation();
                break;
            default:
                $this->error("Ação '{$action}' não reconhecida");
                return 1;
        }

        return 0;
    }

    private function analyzeLogs(): void
    {
        $this->info('📊 Análise Geral dos Logs de Integração');
        $this->line('═══════════════════════════════════════════════');

        $period = (int) $this->option('period');
        $since = now()->subHours($period);

        $logFiles = $this->getLogFiles();
        $totalLogs = 0;
        $errorCount = 0;
        $successCount = 0;

        foreach ($logFiles as $logFile) {
            if (File::exists($logFile)) {
                $logs = $this->parseLogFile($logFile, $since);
                $totalLogs += count($logs);
                
                foreach ($logs as $log) {
                    if (strpos($log['message'], 'Integration completed successfully') !== false) {
                        $successCount++;
                    } elseif (strpos($log['message'], 'Integration failed') !== false) {
                        $errorCount++;
                    }
                }
            }
        }

        $this->line("📈 Estatísticas ({$period}h):");
        $this->line("   Total de logs: {$totalLogs}");
        $this->line("   Sucessos: {$successCount}");
        $this->line("   Erros: {$errorCount}");
        
        if ($totalLogs > 0) {
            $successRate = round(($successCount / $totalLogs) * 100, 2);
            $this->line("   Taxa de sucesso: {$successRate}%");
        }

        
        $this->showTopSystems($logFiles, $since);
    }

    private function analyzeErrors(): void
    {
        $this->info('❌ Análise de Erros');
        $this->line('═══════════════════════════════════════════════');

        $period = (int) $this->option('period');
        $limit = (int) $this->option('limit');
        $since = now()->subHours($period);

        $errorLogs = $this->getErrorLogs($since, $limit);

        if (empty($errorLogs)) {
            $this->info('✅ Nenhum erro encontrado no período especificado');
            return;
        }

        $this->line("🔍 Últimos {$limit} erros ({$period}h):");
        $this->newLine();

        foreach ($errorLogs as $log) {
            $this->line("⏰ " . $log['timestamp']);
            $this->line("🔗 Integration ID: " . ($log['integration_id'] ?? 'N/A'));
            $this->line("👤 User ID: " . ($log['user_id'] ?? 'N/A'));
            $this->line("🖥️ Sistema: " . ($log['system'] ?? 'N/A'));
            $this->line("❌ Erro: " . ($log['error']['message'] ?? 'N/A'));
            $this->line("📍 Arquivo: " . ($log['error']['file'] ?? 'N/A') . ':' . ($log['error']['line'] ?? 'N/A'));
            $this->line('───────────────────────────────────────────────');
        }

        
        $this->analyzeErrorTypes($errorLogs);
    }

    private function analyzePerformance(): void
    {
        $this->info('⚡ Análise de Performance');
        $this->line('═══════════════════════════════════════════════');

        $period = (int) $this->option('period');
        $since = now()->subHours($period);

        $performanceLogs = $this->getPerformanceLogs($since);

        if (empty($performanceLogs)) {
            $this->info('📊 Nenhum dado de performance encontrado no período especificado');
            return;
        }

        
        $executionTimes = array_column($performanceLogs, 'execution_time');
        $executionTimes = array_filter($executionTimes, function($time) {
            return $time !== null && $time > 0;
        });

        if (!empty($executionTimes)) {
            $avgTime = round(array_sum($executionTimes) / count($executionTimes), 2);
            $minTime = round(min($executionTimes), 2);
            $maxTime = round(max($executionTimes), 2);
            $medianTime = round($this->calculateMedian($executionTimes), 2);

            $this->line("⏱️ Tempos de Execução ({$period}h):");
            $this->line("   Média: {$avgTime}s");
            $this->line("   Mínimo: {$minTime}s");
            $this->line("   Máximo: {$maxTime}s");
            $this->line("   Mediana: {$medianTime}s");
        }

        
        $this->showSlowestIntegrations($performanceLogs);
    }

    private function analyzeCorrelation(): void
    {
        $correlationId = $this->option('correlation-id');
        $integrationId = $this->option('integration-id');

        if (!$correlationId && !$integrationId) {
            $this->error('É necessário fornecer --correlation-id ou --integration-id');
            return;
        }

        $this->info('🔗 Análise de Correlação');
        $this->line('═══════════════════════════════════════════════');

        if ($correlationId) {
            $this->analyzeByCorrelationId($correlationId);
        } elseif ($integrationId) {
            $this->analyzeByIntegrationId($integrationId);
        }
    }

    private function getLogFiles(): array
    {
        $logPath = storage_path('logs');
        return [
            $logPath . '/integration.log',
            $logPath . '/integration-errors.log',
            $logPath . '/integration-performance.log',
            $logPath . '/laravel.log'
        ];
    }

    private function parseLogFile(string $filePath, Carbon $since): array
    {
        if (!File::exists($filePath)) {
            return [];
        }

        $logs = [];
        $content = File::get($filePath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            
            if (preg_match('/^\[(.*?)\].*?(\w+):\s*(.*)$/', $line, $matches)) {
                $timestamp = Carbon::parse($matches[1]);
                
                if ($timestamp->gte($since)) {
                    $logs[] = [
                        'timestamp' => $timestamp,
                        'level' => $matches[2],
                        'message' => $matches[3],
                        'raw' => $line
                    ];
                }
            }
        }

        return $logs;
    }

    private function getErrorLogs(Carbon $since, int $limit): array
    {
        $errorLogs = [];
        $logFiles = $this->getLogFiles();

        foreach ($logFiles as $logFile) {
            if (File::exists($logFile)) {
                $logs = $this->parseLogFile($logFile, $since);
                
                foreach ($logs as $log) {
                    if (strpos($log['message'], 'Integration failed') !== false) {
                        $errorLogs[] = $this->parseStructuredLog($log['message']);
                    }
                }
            }
        }

        
        usort($errorLogs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($errorLogs, 0, $limit);
    }

    private function getPerformanceLogs(Carbon $since): array
    {
        $performanceLogs = [];
        $logFiles = $this->getLogFiles();

        foreach ($logFiles as $logFile) {
            if (File::exists($logFile)) {
                $logs = $this->parseLogFile($logFile, $since);
                
                foreach ($logs as $log) {
                    if (strpos($log['message'], 'Integration completed successfully') !== false) {
                        $performanceLogs[] = $this->parseStructuredLog($log['message']);
                    }
                }
            }
        }

        return $performanceLogs;
    }

    private function parseStructuredLog(string $message): array
    {
        
        $data = [];
        
        
        if (preg_match('/\{.*\}/', $message, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if ($jsonData) {
                $data = $jsonData;
            }
        }

        return $data;
    }

    private function showTopSystems(array $logFiles, Carbon $since): void
    {
        $systems = [];
        
        foreach ($logFiles as $logFile) {
            if (File::exists($logFile)) {
                $logs = $this->parseLogFile($logFile, $since);
                
                foreach ($logs as $log) {
                    $parsed = $this->parseStructuredLog($log['message']);
                    if (isset($parsed['system'])) {
                        $system = $parsed['system'];
                        $systems[$system] = ($systems[$system] ?? 0) + 1;
                    }
                }
            }
        }

        if (!empty($systems)) {
            arsort($systems);
            
            $this->newLine();
            $this->line("🏆 Top Sistemas por Volume de Logs:");
            $count = 0;
            foreach ($systems as $system => $logCount) {
                if ($count >= 10) break; 
                $this->line("   {$system}: {$logCount} logs");
                $count++;
            }
        }
    }

    private function analyzeErrorTypes(array $errorLogs): void
    {
        $errorTypes = [];
        
        foreach ($errorLogs as $log) {
            if (isset($log['error']['message'])) {
                $message = $log['error']['message'];
                
                
                if (strpos($message, 'timeout') !== false) {
                    $errorTypes['Timeout'] = ($errorTypes['Timeout'] ?? 0) + 1;
                } elseif (strpos($message, 'memory') !== false) {
                    $errorTypes['Memory'] = ($errorTypes['Memory'] ?? 0) + 1;
                } elseif (strpos($message, 'connection') !== false) {
                    $errorTypes['Connection'] = ($errorTypes['Connection'] ?? 0) + 1;
                } elseif (strpos($message, 'XML') !== false) {
                    $errorTypes['XML Processing'] = ($errorTypes['XML Processing'] ?? 0) + 1;
                } else {
                    $errorTypes['Other'] = ($errorTypes['Other'] ?? 0) + 1;
                }
            }
        }

        if (!empty($errorTypes)) {
            $this->newLine();
            $this->line("📊 Tipos de Erro:");
            arsort($errorTypes);
            foreach ($errorTypes as $type => $count) {
                $this->line("   {$type}: {$count}");
            }
        }
    }

    private function showSlowestIntegrations(array $performanceLogs): void
    {
        
        $slowIntegrations = array_filter($performanceLogs, function($log) {
            return isset($log['execution_time']) && $log['execution_time'] > 0;
        });

        usort($slowIntegrations, function($a, $b) {
            return $b['execution_time'] - $a['execution_time'];
        });

        $slowIntegrations = array_slice($slowIntegrations, 0, 5);

        if (!empty($slowIntegrations)) {
            $this->newLine();
            $this->line("🐌 Integrações Mais Lentas:");
            foreach ($slowIntegrations as $log) {
                $system = $log['system'] ?? 'Unknown';
                $integrationId = $log['integration_id'] ?? 'N/A';
                $executionTime = round($log['execution_time'], 2);
                $this->line("   {$system} (ID:{$integrationId}): {$executionTime}s");
            }
        }
    }

    private function analyzeByCorrelationId(string $correlationId): void
    {
        $this->line("🔍 Buscando logs para correlation ID: {$correlationId}");
        
        
        $this->info("Funcionalidade de busca por correlation ID será implementada com sistema de busca de logs");
    }

    private function analyzeByIntegrationId(int $integrationId): void
    {
        $this->line("🔍 Buscando logs para integration ID: {$integrationId}");
        
        
        $this->info("Funcionalidade de busca por integration ID será implementada com sistema de busca de logs");
    }

    private function calculateMedian(array $numbers): float
    {
        sort($numbers);
        $count = count($numbers);
        $middle = floor($count / 2);
        
        if ($count % 2 == 0) {
            return ($numbers[$middle - 1] + $numbers[$middle]) / 2;
        } else {
            return $numbers[$middle];
        }
    }
}
