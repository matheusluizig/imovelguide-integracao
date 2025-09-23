<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;

class AutomaticPlanIntegration extends Command
{
    protected $signature = 'auto:automaticPlanIntegration {--light : Processa apenas registros recentes} {--force : Força processamento completo}';
    protected $description = 'Processa integrações de planos automaticamente - VERSÃO OTIMIZADA';

    public function handle()
    {
        $startTime = microtime(true);
        $this->info('🚀 Iniciando AutomaticPlanIntegration otimizado...');

        try {
            $cacheKey = 'last_plan_integration_run';
            $isLight = $this->option('light');
            $isForce = $this->option('force');

            
            if ($isForce) {
                $searchFrom = now()->subDays(30);
                $this->info('🔧 Modo FORCE: Processando últimos 30 dias');
            } elseif ($isLight) {
                $searchFrom = now()->subHours(2);
                $this->info('⚡ Modo LIGHT: Processando últimas 2 horas');
            } else {
                $lastRun = Cache::get($cacheKey);
                $searchFrom = $lastRun ? Carbon::parse($lastRun) : now()->subHours(6);
                $this->info('🔄 Modo NORMAL: Processando desde última execução');
            }

            
            $integrations = Integracao::join('integrations_queues', 'integracao_xml.id', '=', 'integrations_queues.integration_id')
                ->join('users', 'integracao_xml.user_id', '=', 'users.id')
                ->where('integracao_xml.status', Integracao::XML_STATUS_NOT_INTEGRATED)
                ->where('users.inative', 0)
                ->where('integrations_queues.status', IntegrationsQueues::STATUS_PENDING)
                ->where('integrations_queues.priority', IntegrationsQueues::PRIORITY_PLAN)
                ->where('integracao_xml.updated_at', '>=', $searchFrom)
                ->select('integracao_xml.*')
                ->orderBy('integracao_xml.updated_at', 'desc')
                ->limit(100) 
                ->get();

            if ($integrations->isEmpty()) {
                $this->info('✅ Nenhuma integração de plano pendente encontrada');
                Cache::put($cacheKey, now()->toDateTimeString(), 3600);
                return 0;
            }

            $this->info("📊 Encontradas {$integrations->count()} integrações de plano pendentes");

            
            $activeJobs = DB::table('jobs')
                ->where('queue', 'priority-integrations')
                ->count();

            $maxJobs = 2; 
            $availableSlots = max(0, $maxJobs - $activeJobs);

            if ($availableSlots === 0) {
                $this->warn("⚠️ Fila de planos ocupada ({$activeJobs}/{$maxJobs} jobs ativos)");
                return 0;
            }

            $this->info("🔄 Slots disponíveis: {$availableSlots}");

            
            $processed = 0;
            $chunkSize = min($availableSlots, 10); 
            $integrationIds = $integrations->take($chunkSize)->pluck('id')->toArray();

            
            $existingJobs = DB::table('jobs')
                ->where('queue', 'priority-integrations')
                ->whereIn(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(payload, "$.integrationId"))'), $integrationIds)
                ->pluck(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(payload, "$.integrationId"))'))
                ->map(function($id) {
                    return (int) $id; 
                })
                ->toArray();

            foreach ($integrations->take($chunkSize) as $integration) {
                try {
                    
                    if (in_array($integration->id, $existingJobs)) {
                        $this->line("⏭️ Integração {$integration->id} já está na fila");
                        continue;
                    }

                    
                    ProcessIntegrationJob::dispatch($integration->id, 'priority-integrations');
                    $processed++;

                    $this->line("✅ Integração {$integration->id} ({$integration->system}) despachada para fila prioritária");

                } catch (\Exception $e) {
                    $this->error("❌ Erro ao processar integração {$integration->id}: " . $e->getMessage());
                    Log::error('AutomaticPlanIntegration error', [
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            
            Cache::put($cacheKey, now()->toDateTimeString(), 3600);

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("✅ Processamento concluído: {$processed} integrações despachadas em {$executionTime}s");

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Erro fatal: " . $e->getMessage());
            Log::error('AutomaticPlanIntegration fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}