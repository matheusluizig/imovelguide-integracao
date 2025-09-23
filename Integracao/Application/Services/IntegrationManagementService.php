<?php

namespace App\Integracao\Application\Services;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\User;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class IntegrationManagementService
{
    public function createIntegration(array $data): IntegrationResult
    {
        return DB::transaction(function () use ($data) {
            $user = User::select('id', 'integration_priority', 'inative')->find($data['user_id']);
            if (!$user) {
                return IntegrationResult::error('Usuário não encontrado');
            }

            $existingIntegration = Integracao::select('id', 'user_id', 'link', 'status', 'first_integration', 'last_integration', 'updated_at')
                ->where('user_id', $data['user_id'])
                ->first();

            if ($existingIntegration) {
                return $this->updateExistingIntegration($existingIntegration, $data, $user);
            } else {
                return $this->createNewIntegration($data, $user);
            }
        });
    }

    private function updateExistingIntegration(Integracao $integration, array $data, User $user): IntegrationResult
    {
        if (isset($data['force']) && $data['force'] && !$integration->first_integration) {
            return IntegrationResult::error('Para forçar uma atualização é necessário que seu XML esteja integrado');
        }
        $integration->update([
            'link' => $data['url'],
            'qtd' => 0,
            'status' => Integracao::XML_STATUS_IN_ANALYSIS,
            'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString(),
        ]);

        if (!$integration->last_integration) {
            $integration->update(['last_integration' => $integration->updated_at]);
        }
        $this->manageIntegrationQueue($integration, $user);

        return IntegrationResult::success($integration, 'Integração atualizada com sucesso');
    }

    private function createNewIntegration(array $data, User $user): IntegrationResult
    {
        $integration = Integracao::create([
            'user_id' => $data['user_id'],
            'link' => $data['url'],
            'status' => Integracao::XML_STATUS_IN_ANALYSIS,
            'created_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString(),
            'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString()
        ]);

        $this->manageIntegrationQueue($integration, $user);

        return IntegrationResult::success($integration, 'Integração criada com sucesso');
    }

    private function manageIntegrationQueue(Integracao $integration, User $user): void
    {
        $existingQueue = IntegrationsQueues::select('id', 'integration_id', 'priority', 'status', 'started_at', 'ended_at', 'completed_at', 'error_message', 'last_error_step', 'error_details', 'execution_time', 'attempts')
            ->where('integration_id', $integration->id)
            ->first();

        if ($existingQueue) {
            $existingQueue->update([
                'priority' => $user->integration_priority,
                'status' => IntegrationsQueues::STATUS_PENDING,
                'started_at' => null,
                'ended_at' => null,
                'completed_at' => null,
                'error_message' => null,
                'last_error_step' => null,
                'error_details' => null,
                'execution_time' => null,
                'attempts' => 0
            ]);
        } else {
            IntegrationsQueues::create([
                'integration_id' => $integration->id,
                'priority' => $user->integration_priority,
                'status' => IntegrationsQueues::STATUS_PENDING,
            ]);
        }

        ProcessIntegrationJob::dispatch($integration->id)
            ->onQueue('normal-integrations');
    }


    public function getQueueOverview(): QueueOverview
    {
        $overview = new QueueOverview();

        $overview->setPendingByPriority(
            IntegrationsQueues::selectRaw('priority, COUNT(*) as count')
                ->where('status', IntegrationsQueues::STATUS_PENDING)
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray()
        );

        $overview->setProcessingIntegrations(
            IntegrationsQueues::select('id', 'integration_id', 'priority', 'status', 'started_at', 'ended_at', 'execution_time')
                ->with(['integration:id,user_id,system,status'])
                ->where('status', IntegrationsQueues::STATUS_IN_PROCESS)
                ->get()
        );

        $overview->setEstimatedProcessingTime($this->calculateEstimatedTime());

        return $overview;
    }

    public function skipIntegration(int $integrationId, string $reason): bool
    {
        $queueRecord = IntegrationsQueues::select('id', 'integration_id', 'status', 'error_message')
            ->where('integration_id', $integrationId)
            ->where('status', IntegrationsQueues::STATUS_PENDING)
            ->first();

        if (!$queueRecord) {
            return false;
        }

        $queueRecord->update([
            'status' => IntegrationsQueues::STATUS_STOPPED,
            'error_message' => "Pulada: {$reason}"
        ]);

        return true;
    }

    private function calculateEstimatedTime(): int
    {
        $pendingCount = IntegrationsQueues::select('id')
            ->where('status', IntegrationsQueues::STATUS_PENDING)
            ->count();
        $avgProcessingTime = 300;

        return $pendingCount * $avgProcessingTime;
    }
}