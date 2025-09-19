<?php

namespace App\Integracao\Application\Services;

use App\Integracao\Domain\Entities\IntegrationsQueues;

class QueueOverview
{
    private array $pendingByPriority = [];
    private $processingIntegrations;
    private int $estimatedProcessingTime = 0;

    public function setPendingByPriority(array $pendingByPriority): void
    {
        $this->pendingByPriority = $pendingByPriority;
    }

    public function setProcessingIntegrations($processingIntegrations): void
    {
        $this->processingIntegrations = $processingIntegrations;
    }

    public function setEstimatedProcessingTime(int $estimatedProcessingTime): void
    {
        $this->estimatedProcessingTime = $estimatedProcessingTime;
    }

    public function toArray(): array
    {
        return [
            'pending_by_priority' => $this->pendingByPriority,
            'processing_count' => $this->processingIntegrations->count(),
            'estimated_processing_time' => $this->estimatedProcessingTime,
            'processing_integrations' => $this->processingIntegrations->map(function ($queue) {
                return [
                    'id' => $queue->integration_id,
                    'system' => $queue->integration->system ?? 'Desconhecido',
                    'started_at' => $queue->started_at,
                    'elapsed_time' => $queue->started_at ? $queue->started_at->diffInSeconds(now()) : 0
                ];
            })
        ];
    }
}
