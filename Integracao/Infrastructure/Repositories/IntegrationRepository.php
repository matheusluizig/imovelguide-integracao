<?php

namespace App\Integracao\Infrastructure\Repositories;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Domain\Entities\IntegrationRun;
use App\Integracao\Domain\Entities\IntegrationRunChunk;
use App\Integracao\Domain\Transaction\IntegrationTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IntegrationRepository
{
    public function findById(int $id): ?Integracao
    {
        return Integracao::select('id', 'user_id', 'link', 'status', 'system', 'qtd', 'first_integration', 'last_integration', 'created_at', 'updated_at')
            ->find($id);
    }

    public function findByIdWithRelations(int $id, array $relations = ['queue', 'user']): ?Integracao
    {
        return Integracao::select('id', 'user_id', 'link', 'status', 'system', 'qtd', 'first_integration', 'last_integration', 'created_at', 'updated_at')
            ->with($relations)
            ->find($id);
    }

    public function getQueue(int $integrationId): ?IntegrationsQueues
    {
        return IntegrationsQueues::select('id', 'integration_id', 'priority', 'status', 'started_at', 'ended_at', 'completed_at', 'error_message', 'last_error_step', 'error_details', 'execution_time', 'attempts', 'created_at', 'updated_at')
            ->where('integration_id', $integrationId)
            ->first();
    }

    public function countImoveis(int $integrationId): int
    {
        $integration = $this->findById($integrationId);
        if (!$integration || !$integration->user) {
            return 0;
        }

        return $integration->user->anuncios()
            ->where('integration_id', $integrationId)
            ->count();
    }

    public function isInProgress(int $integrationId): bool
    {
        $integration = $this->findById($integrationId);
        if (!$integration) {
            return false;
        }

        return in_array($integration->status, [
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE
        ]);
    }

    public function createRun(int $integrationId, int $totalItems = 0): IntegrationRun
    {
        $integration = $this->findById($integrationId);

        return DB::transaction(function () use ($integration, $totalItems) {
            return IntegrationRun::create([
                'integration_id' => $integration->id,
                'user_id' => $integration->user_id,
                'total_items' => $totalItems,
                'processed_items' => 0,
                'status' => 'running'
            ]);
        });
    }

    public function createChunks(IntegrationRun $run, int $totalItems, int $chunkSize): Collection
    {
        return DB::transaction(function () use ($run, $totalItems, $chunkSize) {
            $chunks = collect();

            $run->update(['total_items' => $totalItems]);
            for ($offset = 0; $offset < $totalItems; $offset += $chunkSize) {
                $limit = min($chunkSize, $totalItems - $offset);

                $chunk = IntegrationRunChunk::create([
                    'run_id' => $run->id,
                    'offset' => $offset,
                    'limit' => $limit,
                    'processed' => 0,
                    'status' => 'pending'
                ]);

                $chunks->push($chunk);
            }

            return $chunks;
        });
    }

    public function updateChunkProgress(IntegrationRunChunk $chunk, int $processed): void
    {
        DB::transaction(function () use ($chunk, $processed) {
            $chunk->update([
                'processed' => $processed,
                'status' => 'done'
            ]);

            $run = $chunk->run;
            $run->increment('processed_items', $processed);
            $pendingChunks = $run->chunks()->where('status', '!=', 'done')->count();
            if ($pendingChunks === 0) {
                $run->update(['status' => 'done']);

                IntegrationTransaction::markAsCompleted(
                    $run->integration_id,
                    $run->processed_items
                );
            }
        });
    }

    public function markChunkAsError(IntegrationRunChunk $chunk, string $errorMessage): void
    {
        DB::transaction(function () use ($chunk, $errorMessage) {
            $chunk->update([
                'status' => 'error',
                'error_message' => $errorMessage
            ]);

            $run = $chunk->run;
            $run->update([
                'status' => 'error',
                'error_message' => "Erro no chunk {$chunk->id}: {$errorMessage}"
            ]);
            IntegrationTransaction::markAsError(
                $run->integration_id,
                "Erro no chunk {$chunk->id}: {$errorMessage}",
                'chunk_processing',
                ['chunk_id' => $chunk->id]
            );
        });
    }

    public function getPendingIntegrationsByPriority(int $priority, int $limit = 10): Collection
    {
        return IntegrationsQueues::select('id', 'integration_id', 'priority', 'status', 'created_at')
            ->where('status', IntegrationsQueues::STATUS_PENDING)
            ->where('priority', $priority)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($queue) {
                return $this->findById($queue->integration_id);
            })
            ->filter();
    }

    public function getProcessingIntegrations(): Collection
    {
        return IntegrationsQueues::select('id', 'integration_id', 'priority', 'status', 'started_at', 'ended_at', 'execution_time')
            ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
            ->get()
            ->map(function ($queue) {
                return $this->findById($queue->integration_id);
            })
            ->filter();
    }
}
