<?php

namespace App\Integracao\Infrastructure\Repositories;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
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