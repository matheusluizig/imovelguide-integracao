<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Carbon\Carbon;

class IntegrationDashboard extends Command
{
    protected $signature = 'integration:dashboard
                            {--refresh=5 : Intervalo de atualização em segundos}
                            {--once : Executar apenas uma vez}
                            {--limit=20 : Limite de integrações para exibir}';

    protected $description = 'Dashboard visual em tempo real do sistema de integração';

    private $terminalWidth = 120;
    private $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'bold' => "\033[1m",
        'reset' => "\033[0m"
    ];

    public function handle()
    {
        $this->terminalWidth = $this->getTerminalWidth();
        $refreshInterval = (int) $this->option('refresh');
        $runOnce = $this->option('once');
        $limit = (int) $this->option('limit');

        if ($runOnce) {
            $this->displayDashboard($limit);
        } else {
            $this->displayRealtimeDashboard($refreshInterval, $limit);
        }

        return 0;
    }

    private function displayRealtimeDashboard(int $refreshInterval, int $limit): void
    {
        $this->info("🚀 Dashboard de Integrações - Tempo Real");
        $this->line("Pressione Ctrl+C para sair");
        $this->newLine();

        while (true) {
            
            $this->clearScreen();

            
            $this->displayDashboard($limit);

            
            sleep($refreshInterval);
        }
    }

    private function displayDashboard(int $limit): void
    {
        $this->displayHeader();
        $this->displaySystemStats();
        $this->displayQueueStats();
        $this->displayRunningIntegrations($limit);
        $this->displayPendingQueue($limit);
        $this->displayFooter();
    }

    private function displayHeader(): void
    {
        $this->line($this->colors['bold'] . $this->colors['cyan'] . str_repeat('═', $this->terminalWidth) . $this->colors['reset']);
        $this->line($this->colors['bold'] . $this->colors['cyan'] . '🚀 DASHBOARD DE INTEGRAÇÕES - ' . now()->format('H:i:s') . $this->colors['reset']);
        $this->line($this->colors['bold'] . $this->colors['cyan'] . str_repeat('═', $this->terminalWidth) . $this->colors['reset']);
        $this->newLine();
    }

    private function displaySystemStats(): void
    {
        $stats = $this->getSystemStats();

        $this->line($this->colors['bold'] . $this->colors['blue'] . '📊 ESTATÍSTICAS DO SISTEMA' . $this->colors['reset']);
        $this->line(str_repeat('─', $this->terminalWidth));

        $line1 = sprintf(
            "Total: %s%d%s | Ativas: %s%d%s | Processando: %s%d%s | Erros: %s%d%s",
            $this->colors['white'], $stats['total_integrations'], $this->colors['reset'],
            $this->colors['green'], $stats['active_integrations'], $this->colors['reset'],
            $this->colors['yellow'], $stats['processing_integrations'], $this->colors['reset'],
            $this->colors['red'], $stats['error_integrations'], $this->colors['reset']
        );

        $line2 = sprintf(
            "Concluídas Hoje: %s%d%s | Erros Hoje: %s%d%s | Taxa Sucesso: %s%.1f%%%s",
            $this->colors['green'], $stats['completed_today'], $this->colors['reset'],
            $this->colors['red'], $stats['errors_today'], $this->colors['reset'],
            $this->colors['cyan'], $stats['success_rate'], $this->colors['reset']
        );

        $this->line($line1);
        $this->line($line2);
        $this->newLine();
    }

    private function displayQueueStats(): void
    {
        $queueStats = $this->getQueueStats();

        $this->line($this->colors['bold'] . $this->colors['magenta'] . '📋 ESTATÍSTICAS DA FILA' . $this->colors['reset']);
        $this->line(str_repeat('─', $this->terminalWidth));

        $line1 = sprintf(
            "Redis: %s%d%s | Pendentes: %s%d%s | Processando: %s%d%s | Concluídas: %s%d%s",
            $this->colors['white'], $queueStats['redis_jobs'], $this->colors['reset'],
            $this->colors['yellow'], $queueStats['pending_jobs'], $this->colors['reset'],
            $this->colors['blue'], $queueStats['processing_jobs'], $this->colors['reset'],
            $this->colors['green'], $queueStats['completed_jobs'], $this->colors['reset']
        );

        $line2 = sprintf(
            "Tempo Médio: %s%.1fs%s | Throughput: %s%.1f/h%s | Workers: %s%d%s",
            $this->colors['cyan'], $queueStats['avg_execution_time'], $this->colors['reset'],
            $this->colors['cyan'], $queueStats['throughput'], $this->colors['reset'],
            $this->colors['white'], $queueStats['active_workers'], $this->colors['reset']
        );

        $this->line($line1);
        $this->line($line2);
        $this->newLine();
    }

    private function displayRunningIntegrations(int $limit): void
    {
        $runningIntegrations = $this->getRunningIntegrations($limit);

        $this->line($this->colors['bold'] . $this->colors['green'] . '🔄 INTEGRAÇÕES EM EXECUÇÃO' . $this->colors['reset']);
        $this->line(str_repeat('─', $this->terminalWidth));

        if (empty($runningIntegrations)) {
            $this->line($this->colors['yellow'] . '   Nenhuma integração em execução no momento' . $this->colors['reset']);
            $this->newLine();
            return;
        }

        foreach ($runningIntegrations as $integration) {
            $this->displayRunningIntegration($integration);
        }
        $this->newLine();
    }

    private function displayRunningIntegration($integration): void
    {
        $system = $integration->system ?? 'Unknown';
        $integrationId = $integration->integration_id;
        $userName = $integration->user_name ?? 'Unknown';
        $startedAt = Carbon::parse($integration->started_at);
        $elapsed = $startedAt->diffForHumans(now(), true);

        
        $estimatedTotalTime = 300; 
        $elapsedSeconds = $startedAt->diffInSeconds(now());
        $progress = min(round(($elapsedSeconds / $estimatedTotalTime) * 100), 95);

        
        $progressBar = $this->createProgressBar($progress, 40);

        
        $color = $this->getProgressColor($progress);

        $line = sprintf(
            "%s[%s]%s %s%-15s%s %sID:%s%d%s %s%s%s %s%s%s",
            $color, $progressBar, $this->colors['reset'],
            $this->colors['bold'], $system, $this->colors['reset'],
            $this->colors['white'], $this->colors['reset'], $integrationId, $this->colors['reset'],
            $this->colors['cyan'], $userName, $this->colors['reset'],
            $this->colors['yellow'], $elapsed, $this->colors['reset']
        );

        $this->line($line);
    }

    private function displayPendingQueue(int $limit): void
    {
        $pendingIntegrations = $this->getPendingIntegrations($limit);

        $this->line($this->colors['bold'] . $this->colors['yellow'] . '⏳ FILA DE INTEGRAÇÕES PENDENTES' . $this->colors['reset']);
        $this->line(str_repeat('─', $this->terminalWidth));

        if (empty($pendingIntegrations)) {
            $this->line($this->colors['green'] . '   ✅ Fila vazia - todas as integrações processadas!' . $this->colors['reset']);
            $this->newLine();
            return;
        }

        $this->line(sprintf(
            "%s%-4s %s%-15s %s%-20s %s%-8s %s%-12s%s",
            $this->colors['bold'], 'Pos', $this->colors['reset'],
            $this->colors['bold'], 'Sistema', $this->colors['reset'],
            $this->colors['bold'], 'Usuário', $this->colors['reset'],
            $this->colors['bold'], 'Prioridade', $this->colors['reset'],
            $this->colors['bold'], 'Criado', $this->colors['reset'],
            $this->colors['reset']
        ));

        $this->line(str_repeat('─', $this->terminalWidth));

        foreach ($pendingIntegrations as $index => $integration) {
            $this->displayPendingIntegration($integration, $index + 1);
        }
        $this->newLine();
    }

    private function displayPendingIntegration($integration, int $position): void
    {
        $system = $integration->system ?? 'Unknown';
        $integrationId = $integration->integration_id;
        $userName = $integration->user_name ?? 'Unknown';
        $priority = $integration->priority ?? 0;
        $createdAt = Carbon::parse($integration->created_at);
        $timeAgo = $createdAt->diffForHumans(now(), true);

        
        $priorityColor = $this->getPriorityColor($priority);

        $line = sprintf(
            "%s%-4d %s%-15s %s%-20s %s%-8s %s%-12s%s",
            $this->colors['white'], $position,
            $this->colors['cyan'], $system,
            $this->colors['white'], $this->truncateString($userName, 20),
            $priorityColor, $priority,
            $this->colors['yellow'], $timeAgo,
            $this->colors['reset']
        );

        $this->line($line);
    }

    private function displayFooter(): void
    {
        $this->line(str_repeat('═', $this->terminalWidth));
        $this->line($this->colors['cyan'] . '💡 Comandos úteis:' . $this->colors['reset']);
        $this->line($this->colors['white'] . '   php artisan integration:worker status' . $this->colors['reset'] . ' - Status dos workers');
        $this->line($this->colors['white'] . '   php artisan integration:metrics' . $this->colors['reset'] . ' - Métricas detalhadas');
        $this->line($this->colors['white'] . '   php artisan integration:logs analyze' . $this->colors['reset'] . ' - Análise de logs');
        $this->line(str_repeat('═', $this->terminalWidth));
    }

    private function getSystemStats(): array
    {
        $today = now()->startOfDay();

        $totalIntegrations = Integracao::select('id')->count();
        $activeIntegrations = Integracao::select('id')->where('status', Integracao::XML_STATUS_INTEGRATED)->count();
        $processingIntegrations = Integracao::select('id')->whereIn('status', [
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE
        ])->count();
        $errorIntegrations = Integracao::select('id')->where('status', Integracao::XML_STATUS_CRM_ERRO)->count();

        $completedToday = IntegrationsQueues::select('id')
            ->where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $today)->count();
        $errorsToday = IntegrationsQueues::select('id')
            ->where('status', IntegrationsQueues::STATUS_ERROR)
            ->where('updated_at', '>=', $today)->count();

        $totalProcessed = $completedToday + $errorsToday;
        $successRate = $totalProcessed > 0 ? ($completedToday / $totalProcessed) * 100 : 0;

        return [
            'total_integrations' => $totalIntegrations,
            'active_integrations' => $activeIntegrations,
            'processing_integrations' => $processingIntegrations,
            'error_integrations' => $errorIntegrations,
            'completed_today' => $completedToday,
            'errors_today' => $errorsToday,
            'success_rate' => $successRate
        ];
    }

    private function getQueueStats(): array
    {
        $today = now()->startOfDay();

        $redisJobs = DB::table('jobs')->select('id')->whereIn('queue', ['priority-integrations', 'level-integrations', 'normal-integrations'])->count();
        $pendingJobs = IntegrationsQueues::select('id')->where('status', IntegrationsQueues::STATUS_PENDING)->count();
        $processingJobs = IntegrationsQueues::select('id')->where('status', IntegrationsQueues::STATUS_IN_PROCESS)->count();
        $completedJobs = IntegrationsQueues::select('id')
            ->where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $today)->count();

        $avgExecutionTime = IntegrationsQueues::select('execution_time')
            ->where('status', IntegrationsQueues::STATUS_DONE)
            ->where('completed_at', '>=', $today)
            ->whereNotNull('execution_time')
            ->avg('execution_time') ?? 0;

        $throughput = $completedJobs / max(1, now()->diffInHours($today));
        $activeWorkers = $this->getActiveWorkersCount();

        return [
            'redis_jobs' => $redisJobs,
            'pending_jobs' => $pendingJobs,
            'processing_jobs' => $processingJobs,
            'completed_jobs' => $completedJobs,
            'avg_execution_time' => $avgExecutionTime,
            'throughput' => $throughput,
            'active_workers' => $activeWorkers
        ];
    }

    private function getRunningIntegrations(int $limit): array
    {
        return DB::table('integrations_queues as iq')
            ->join('integracao_xml as ix', 'iq.integration_id', '=', 'ix.id')
            ->join('users as u', 'ix.user_id', '=', 'u.id')
            ->where('iq.status', IntegrationsQueues::STATUS_IN_PROCESS)
            ->select([
                'iq.integration_id',
                'iq.started_at',
                'ix.system',
                'u.name as user_name'
            ])
            ->orderBy('iq.started_at', 'asc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function getPendingIntegrations(int $limit): array
    {
        return DB::table('integrations_queues as iq')
            ->join('integracao_xml as ix', 'iq.integration_id', '=', 'ix.id')
            ->join('users as u', 'ix.user_id', '=', 'u.id')
            ->where('iq.status', IntegrationsQueues::STATUS_PENDING)
            ->select([
                'iq.integration_id',
                'iq.priority',
                'iq.created_at',
                'ix.system',
                'u.name as user_name'
            ])
            ->orderBy('iq.priority', 'desc')
            ->orderBy('iq.created_at', 'asc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function createProgressBar(int $progress, int $width): string
    {
        $filled = round(($progress / 100) * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        return sprintf('%s %d%%', $bar, $progress);
    }

    private function getProgressColor(int $progress): string
    {
        if ($progress < 30) {
            return $this->colors['red'];
        }
        if ($progress < 70) {
            return $this->colors['yellow'];
        }
        return $this->colors['green'];
    }

    private function getPriorityColor(int $priority): string
    {
        if ($priority >= 8) {
            return $this->colors['red'];
        }
        if ($priority >= 5) {
            return $this->colors['yellow'];
        }
        return $this->colors['green'];
    }

    private function truncateString(string $string, int $length): string
    {
        return strlen($string) > $length ? substr($string, 0, $length - 3) . '...' : $string;
    }

    private function getActiveWorkersCount(): int
    {
        try {
            $output = shell_exec('sudo supervisorctl status integration-worker:* 2>/dev/null | grep RUNNING | wc -l');
            return (int) trim($output);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getTerminalWidth(): int
    {
        try {
            $width = (int) shell_exec('tput cols 2>/dev/null');
            return $width > 0 ? $width : 120;
        } catch (\Exception $e) {
            return 120;
        }
    }

    private function clearScreen(): void
    {
        
        echo "\033[2J\033[H";
    }
}