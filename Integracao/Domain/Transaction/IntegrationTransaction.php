<?php

namespace App\Integracao\Domain\Transaction;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class IntegrationTransaction
{
    public static function markAsProcessing(int $integrationId): void
    {
        DB::transaction(function () use ($integrationId) {
            $integration = Integracao::findOrFail($integrationId);
            $queue = self::getOrCreateQueue($integrationId);
            
            $integration->update([
                'status' => Integracao::XML_STATUS_IN_UPDATE_BOTH,
                'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString()
            ]);
            $queue->update([
                'status' => IntegrationsQueues::STATUS_IN_PROCESS,
                'started_at' => now(),
                'error_message' => null,
                'last_error_step' => null,
                'error_details' => null
            ]);
        });
    }
    
    public static function markAsCompleted(int $integrationId, ?int $qtdImoveis = null, ?float $executionTime = null): void
    {
        DB::transaction(function () use ($integrationId, $qtdImoveis, $executionTime) {
            $integration = Integracao::findOrFail($integrationId);
            $queue = self::getOrCreateQueue($integrationId);
            
            if ($qtdImoveis === null) {
                $qtdImoveis = $integration->user->anuncios()->where('integration_id', $integrationId)->count();
            }
            $integration->update([
                'status' => Integracao::XML_STATUS_INTEGRATED,
                'qtd' => $qtdImoveis,
                'last_integration' => Carbon::now('America/Sao_Paulo')->toDateTimeString(),
                'first_integration' => $integration->first_integration ?: Carbon::now('America/Sao_Paulo')->toDateTimeString(),
                'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString()
            ]);
            
            $queue->update([
                'status' => IntegrationsQueues::STATUS_DONE,
                'completed_at' => now(),
                'ended_at' => now(),
                'execution_time' => $executionTime,
                'error_message' => null
            ]);
        });
    }
    
    public static function markAsError(int $integrationId, string $errorMessage, ?string $errorStep = null, ?array $errorDetails = null, ?float $executionTime = null): void
    {
        DB::transaction(function () use ($integrationId, $errorMessage, $errorStep, $errorDetails, $executionTime) {
            $integration = Integracao::findOrFail($integrationId);
            $queue = self::getOrCreateQueue($integrationId);
            
            $integration->update([
                'status' => Integracao::XML_STATUS_IN_ANALYSIS,
                'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString()
            ]);
            
            $queue->update([
                'status' => IntegrationsQueues::STATUS_ERROR,
                'completed_at' => now(),
                'ended_at' => now(),
                'error_message' => $errorMessage,
                'last_error_step' => $errorStep ?: 'unknown',
                'error_details' => $errorDetails,
                'execution_time' => $executionTime
            ]);
        });
    }
    
    public static function prepareForReprocessing(int $integrationId, ?int $priority = null): void
    {
        DB::transaction(function () use ($integrationId, $priority) {
            $integration = Integracao::findOrFail($integrationId);
            $queue = self::getOrCreateQueue($integrationId);
            
            if ($priority === null) {
                $priority = $integration->user->integration_priority ?? 0;
            }
            $integration->update([
                'status' => Integracao::XML_STATUS_IN_ANALYSIS,
                'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString()
            ]);
            
            $queue->update([
                'priority' => $priority,
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
        });
    }
    
    public static function markAsStopped(int $integrationId, string $reason): void
    {
        DB::transaction(function () use ($integrationId, $reason) {
            $integration = Integracao::findOrFail($integrationId);
            $queue = self::getOrCreateQueue($integrationId);
            
            $integration->update([
                'status' => Integracao::XML_STATUS_PROGRAMMERS_SOLVE,
                'updated_at' => Carbon::now('America/Sao_Paulo')->toDateTimeString()
            ]);
            
            $queue->update([
                'status' => IntegrationsQueues::STATUS_STOPPED,
                'completed_at' => now(),
                'ended_at' => now(),
                'error_message' => "Pulada: {$reason}"
            ]);
        });
    }
    
    private static function getOrCreateQueue(int $integrationId): IntegrationsQueues
    {
        return IntegrationsQueues::firstOrCreate(
            ['integration_id' => $integrationId],
            [
                'status' => IntegrationsQueues::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}
