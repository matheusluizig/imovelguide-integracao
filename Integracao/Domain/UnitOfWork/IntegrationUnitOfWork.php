<?php

namespace App\Integracao\Domain\UnitOfWork;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class IntegrationUnitOfWork
{
    private array $pendingOperations = [];
    private bool $isCommitted = false;
    public function registerIntegration(Integracao $integration, array $data): self
    {
        $this->pendingOperations[] = [
            'type' => 'integration',
            'model' => $integration,
            'data' => $data
        ];

        return $this;
    }

    public function registerQueue(IntegrationsQueues $queue, array $data): self
    {
        $this->pendingOperations[] = [
            'type' => 'queue',
            'model' => $queue,
            'data' => $data
        ];

        return $this;
    }


    public function registerStatusUpdate(
        Integracao $integration,
        ?IntegrationsQueues $queue = null,
        ?int $status = null,
        array $additionalData = []
    ): self {
        $now = Carbon::now('America/Sao_Paulo')->toDateTimeString();
        $integrationData = array_merge([
            'updated_at' => $now
        ], $additionalData);

        if ($status !== null) {
            $integrationData['status'] = $status;
        }

        $this->registerIntegration($integration, $integrationData);

        if ($queue) {
            $queueData = array_merge([
                'updated_at' => now()
            ], $additionalData);

            if ($status !== null) {
                $queueData['status'] = $this->mapIntegrationStatusToQueueStatus($status);
            }

            $this->registerQueue($queue, $queueData);
        }

        return $this;
    }

    public function commit(): void
    {
        if ($this->isCommitted) {
            throw new \RuntimeException('UnitOfWork já foi committed');
        }

        if (empty($this->pendingOperations)) {
            $this->isCommitted = true;
            return;
        }

        DB::transaction(function () {
            foreach ($this->pendingOperations as $operation) {
                $operation['model']->update($operation['data']);
            }
        });

        $this->pendingOperations = [];
        $this->isCommitted = true;
    }

    private function mapIntegrationStatusToQueueStatus(int $integrationStatus): int
    {
        return match ($integrationStatus) {
            Integracao::XML_STATUS_INTEGRATED => IntegrationsQueues::STATUS_DONE,
            Integracao::XML_STATUS_IN_ANALYSIS,
            Integracao::XML_STATUS_PROGRAMMERS_SOLVE,
            Integracao::XML_STATUS_CRM_ERRO,
            Integracao::XML_STATUS_LINKS_NOT_WORKING,
            Integracao::XML_STATUS_WRONG_MODEL => IntegrationsQueues::STATUS_ERROR,
            Integracao::XML_STATUS_IN_UPDATE_BOTH,
            Integracao::XML_STATUS_IN_DATA_UPDATE,
            Integracao::XML_STATUS_IN_IMAGE_UPDATE => IntegrationsQueues::STATUS_IN_PROCESS,
            default => IntegrationsQueues::STATUS_PENDING
        };
    }

}