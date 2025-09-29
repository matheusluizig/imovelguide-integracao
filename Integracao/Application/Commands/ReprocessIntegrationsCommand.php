<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class ReprocessIntegrationsCommand extends Command
{
    protected $signature = 'integration:reprocess 
                            {--status=* : Status das integrações para reprocessar (ex: 3,1,11)}
                            {--queue-status=* : Status da fila para reprocessar (ex: 3,4)}
                            {--limit=50 : Limite de integrações para reprocessar}
                            {--dry-run : Apenas mostrar quais seriam reprocessadas}
                            {--priority= : Prioridade específica (0=normal, 1=level, 2=plan)}
                            {--force : Forçar reprocessamento mesmo com jobs existentes}';

    protected $description = 'Reprocessa integrações baseado nos status especificados sem duplicatas';

    public function handle(): int
    {
        $this->info("🔄 Iniciando reprocessamento de integrações...");

        $integrationStatuses = $this->getStatusArray('status', [
            Integracao::XML_STATUS_IN_ANALYSIS,
            Integracao::XML_STATUS_CRM_ERRO
        ]);

        $queueStatuses = $this->getStatusArray('queue-status', [
            IntegrationsQueues::STATUS_STOPPED,
            IntegrationsQueues::STATUS_ERROR
        ]);

        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $priority = $this->option('priority');
        $force = $this->option('force');

        $this->info("Filtros aplicados:");
        $this->line("  - Status da integração: " . implode(', ', $integrationStatuses));
        $this->line("  - Status da fila: " . implode(', ', $queueStatuses));
        $this->line("  - Limite: {$limit}");
        if ($priority !== null) {
            $this->line("  - Prioridade: {$priority}");
        }
        if ($dryRun) {
            $this->warn("  - MODO DRY-RUN: Nenhuma alteração será feita");
        }
        if ($force) {
            $this->warn("  - MODO FORCE: Ignorará jobs já na fila");
        }

        $query = $this->buildQuery($integrationStatuses, $queueStatuses, $priority, $limit);
        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->warn("❌ Nenhuma integração encontrada com os critérios especificados.");
            return 0;
        }

        $this->info("✅ Encontradas {$integrations->count()} integrações para reprocessamento:");
        $this->displayIntegrationsTable($integrations);

        if (!$dryRun && !$this->confirm('Deseja continuar com o reprocessamento?')) {
            $this->info("Operação cancelada.");
            return 0;
        }

        if (!$dryRun) {
            return $this->executeReprocessing($integrations, $force);
        }

        $this->info("✅ Dry-run concluído. Nenhuma alteração foi feita.");
        return 0;
    }

    private function getStatusArray(string $option, array $default): array
    {
        $statuses = $this->option($option);

        if (empty($statuses)) {
            return $default;
        }

        $result = [];
        foreach ($statuses as $status) {
            $parts = explode(',', $status);
            foreach ($parts as $part) {
                $result[] = (int) trim($part);
            }
        }

        return array_unique($result);
    }

    private function buildQuery(array $integrationStatuses, array $queueStatuses, ?string $priority, int $limit)
    {
        $query = Integracao::with(['user', 'queue'])
            ->whereIn('status', $integrationStatuses)
            ->whereHas('queue', function ($q) use ($queueStatuses) {
                $q->whereIn('status', $queueStatuses);
            })
            ->whereHas('user', function ($q) {
                $q->where('inative', 0);
            });

        if ($priority !== null) {
            $query->whereHas('queue', function ($q) use ($priority) {
                $q->where('priority', (int) $priority);
            });
        }

        return $query->orderBy('updated_at', 'desc')->limit($limit);
    }

    private function displayIntegrationsTable($integrations): void
    {
        $headers = ['ID', 'Usuário', 'Status Int.', 'Status Fila', 'Prioridade', 'Job Existente', 'Última Atualização'];

        $rows = $integrations->map(function ($integration) {
            $hasExistingJob = $this->hasExistingJob($integration->id) ? '🔄 Sim' : '✅ Não';

            return [
                $integration->id,
                substr($integration->user->name ?? 'N/A', 0, 15),
                $this->getIntegrationStatusName($integration->status),
                $this->getQueueStatusName($integration->queue->status ?? 0),
                $this->getPriorityName($integration->queue->priority ?? 0),
                $hasExistingJob,
                $integration->updated_at->format('d/m/Y H:i:s')
            ];
        })->toArray();

        $this->table($headers, $rows);
    }

    private function executeReprocessing($integrations, bool $force): int
    {
        $this->info("🚀 Iniciando reprocessamento...");

        $successCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($integrations->count());
        $progressBar->start();

        foreach ($integrations as $integration) {
            try {
                if (!$force && $this->hasExistingJob($integration->id)) {
                    $skippedCount++;
                    Log::channel('integration')->info("Skipped reprocessing - job already exists", [
                        'integration_id' => $integration->id,
                        'command' => 'reprocess'
                    ]);
                    $progressBar->advance();
                    continue;
                }

                DB::transaction(function () use ($integration, &$successCount) {
                    $queue = $integration->queue;

                    $queue->update([
                        'status' => IntegrationsQueues::STATUS_PENDING,
                        'attempts' => 0,
                        'started_at' => null,
                        'ended_at' => null,
                        'completed_at' => null,
                        'error_message' => null,
                        'error_details' => null,
                        'last_error_step' => null,
                        'execution_time' => null,
                        'updated_at' => now()
                    ]);

                    $integration->update([
                        'status' => Integracao::XML_STATUS_NOT_INTEGRATED,
                        'updated_at' => now()
                    ]);

                    ProcessIntegrationJob::dispatch($integration->id)
                        ->onQueue($this->getQueueName($queue->priority));

                    $successCount++;
                });

                Log::channel('integration')->info("Integration queued for reprocessing", [
                    'integration_id' => $integration->id,
                    'user_id' => $integration->user_id,
                    'command' => 'reprocess'
                ]);

            } catch (\Exception $e) {
                Log::channel('integration')->error("Failed to reprocess integration", [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                    'command' => 'reprocess'
                ]);

                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Reprocessamento concluído:");
        $this->line("  - Sucessos: {$successCount}");
        $this->line("  - Ignorados (job existente): {$skippedCount}");
        if ($errorCount > 0) {
            $this->error("  - Erros: {$errorCount}");
        }

        if ($successCount > 0) {
            $this->info("📋 Jobs de integração foram adicionados às filas para processamento.");
        }

        return $errorCount > 0 ? 1 : 0;
    }

    private function hasExistingJob(int $integrationId): bool
    {
        try {
            $existingJobs = DB::table('jobs')
                ->where('payload', 'like', '%"integrationId":' . $integrationId . '%')
                ->whereIn('queue', [
                    'priority-integrations',
                    'level-integrations',
                    'normal-integrations'
                ])
                ->count();

            return $existingJobs > 0;

        } catch (\Exception $e) {
            Log::channel('integration')->error("Error checking existing jobs", [
                'integration_id' => $integrationId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function getIntegrationStatusName(int $status): string
    {
        $statuses = [
            Integracao::XML_STATUS_NOT_INTEGRATED => 'Não Integrado',
            Integracao::XML_STATUS_IGNORED => 'Ignorado',
            Integracao::XML_STATUS_INTEGRATED => 'Integrado',
            Integracao::XML_STATUS_IN_ANALYSIS => 'Em Análise',
            Integracao::XML_STATUS_INATIVO => 'Inativo',
            Integracao::XML_STATUS_IN_UPDATE_BOTH => 'Atualizando',
            Integracao::XML_STATUS_IN_DATA_UPDATE => 'Atualizando Dados',
            Integracao::XML_STATUS_IN_IMAGE_UPDATE => 'Atualizando Imagens',
            Integracao::XML_STATUS_PROGRAMMERS_SOLVE => 'Programadores',
            Integracao::XML_STATUS_LINKS_NOT_WORKING => 'Link Quebrado',
            Integracao::XML_STATUS_CRM_ERRO => 'CRM Erro',
            Integracao::XML_STATUS_WRONG_MODEL => 'Modelo Errado'
        ];

        return $statuses[$status] ?? "Status {$status}";
    }

    private function getQueueStatusName(int $status): string
    {
        $statuses = [
            IntegrationsQueues::STATUS_PENDING => 'Pendente',
            IntegrationsQueues::STATUS_IN_PROCESS => 'Processando',
            IntegrationsQueues::STATUS_DONE => 'Concluído',
            IntegrationsQueues::STATUS_STOPPED => 'Parado',
            IntegrationsQueues::STATUS_ERROR => 'Erro'
        ];

        return $statuses[$status] ?? "Status {$status}";
    }

    private function getPriorityName(int $priority): string
    {
        $priorities = [
            IntegrationsQueues::PRIORITY_NORMAL => 'Normal',
            IntegrationsQueues::PRIORITY_LEVEL => 'Level',
            IntegrationsQueues::PRIORITY_PLAN => 'Plano'
        ];

        return $priorities[$priority] ?? "Prioridade {$priority}";
    }

    private function getQueueName(int $priority): string
    {
        return match ($priority) {
            IntegrationsQueues::PRIORITY_PLAN => 'priority-integrations',
            IntegrationsQueues::PRIORITY_LEVEL => 'level-integrations',
            default => 'normal-integrations'
        };
    }
}
