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

class AutomaticNormalIntegration extends Command
{
    protected $signature = 'auto:automaticNormalIntegration {--light : Processa apenas registros recentes}';
    protected $description = 'Processa integrações normais automaticamente - VERSÃO OTIMIZADA';

    public function handle()
    {
        $startTime = microtime(true);
        $this->info('🚀 Iniciando AutomaticNormalIntegration otimizado...');

        try {
            $cacheKey = 'last_normal_integration_run';
            $isLight = $this->option('light');

            // Determinar período de busca
            if ($isLight) {
                $searchFrom = now()->subHours(1);
                $this->info('⚡ Modo LIGHT: Processando última hora');
            } else {
                $lastRun = Cache::get($cacheKey);
                $searchFrom = $lastRun ? Carbon::parse($lastRun) : now()->subHours(4);
                $this->info('🔄 Modo NORMAL: Processando desde última execução');
            }

            // Buscar integrações normais pendentes - Query otimizada com joins
            $integrations = Integracao::join('integrations_queues', 'integracao_xml.id', '=', 'integrations_queues.integration_id')
                ->join('users', 'integracao_xml.user_id', '=', 'users.id')
                ->where('integracao_xml.status', Integracao::XML_STATUS_NOT_INTEGRATED)
                ->where('users.inative', 0)
                ->where('integrations_queues.status', IntegrationsQueues::STATUS_PENDING)
                ->where('integrations_queues.priority', IntegrationsQueues::PRIORITY_NORMAL)
                ->where('integracao_xml.updated_at', '>=', $searchFrom)
                ->select('integracao_xml.*')
                ->orderBy('integracao_xml.updated_at', 'desc')
                ->limit(20) // Limite menor para integrações normais
                ->get();

            if ($integrations->isEmpty()) {
                $this->info('✅ Nenhuma integração normal pendente encontrada');
                Cache::put($cacheKey, now()->toDateTimeString(), 3600);
                return 0;
            }

            $this->info("📊 Encontradas {$integrations->count()} integrações normais pendentes");

            // Verificar jobs ativos na fila
            $activeJobs = DB::table('jobs')
                ->where('queue', 'normal-integrations')
                ->count();

            $maxJobs = 3; // Máximo 3 jobs simultâneos para normais
            $availableSlots = max(0, $maxJobs - $activeJobs);

            if ($availableSlots === 0) {
                $this->warn("⚠️ Fila normal ocupada ({$activeJobs}/{$maxJobs} jobs ativos)");
                return 0;
            }

            $this->info("🔄 Slots disponíveis: {$availableSlots}");

            // Processar integrações em lotes - Otimizado para evitar N+1
            $processed = 0;
            $chunkSize = min($availableSlots, 5); // Máximo 5 por execução
            $integrationIds = $integrations->take($chunkSize)->pluck('id')->toArray();

            // Verificar jobs existentes em uma única query - Otimizada com JSON_UNQUOTE
            $existingJobs = DB::table('jobs')
                ->where('queue', 'normal-integrations')
                ->whereIn(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(payload, "$.integrationId"))'), $integrationIds)
                ->pluck(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(payload, "$.integrationId"))'))
                ->map(function($id) {
                    return (int) $id; // Converter para int para comparação
                })
                ->toArray();

            foreach ($integrations->take($chunkSize) as $integration) {
                try {
                    // Verificar se já existe job para esta integração
                    if (in_array($integration->id, $existingJobs)) {
                        $this->line("⏭️ Integração {$integration->id} já está na fila");
                        continue;
                    }

                    // Despachar job
                    ProcessIntegrationJob::dispatch($integration->id, 'normal-integrations');
                    $processed++;

                    $this->line("✅ Integração {$integration->id} ({$integration->system}) despachada para fila normal");

                } catch (\Exception $e) {
                    $this->error("❌ Erro ao processar integração {$integration->id}: " . $e->getMessage());
                    Log::error('AutomaticNormalIntegration error', [
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Atualizar cache
            Cache::put($cacheKey, now()->toDateTimeString(), 3600);

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("✅ Processamento concluído: {$processed} integrações despachadas em {$executionTime}s");

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Erro fatal: " . $e->getMessage());
            Log::error('AutomaticNormalIntegration fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
