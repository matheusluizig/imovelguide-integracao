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

class AutomaticLevelIntegration extends Command
{
    protected $signature = 'auto:automaticLevelIntegration {--light : Processa apenas registros recentes}';
    protected $description = 'Processa integrações de nível automaticamente - VERSÃO OTIMIZADA';

    public function handle()
    {
        $startTime = microtime(true);
        $this->info('🚀 Iniciando AutomaticLevelIntegration otimizado...');

        try {
            $cacheKey = 'last_level_integration_run';
            $isLight = $this->option('light');

            // Determinar período de busca
            if ($isLight) {
                $searchFrom = now()->subHours(1);
                $this->info('⚡ Modo LIGHT: Processando última hora');
            } else {
                $lastRun = Cache::get($cacheKey);
                $searchFrom = $lastRun ? Carbon::parse($lastRun) : now()->subHours(3);
                $this->info('🔄 Modo NORMAL: Processando desde última execução');
            }

            // Buscar integrações de nível pendentes - Query otimizada com joins
            $integrations = Integracao::join('integrations_queues', 'integracao_xml.id', '=', 'integrations_queues.integration_id')
                ->join('users', 'integracao_xml.user_id', '=', 'users.id')
                ->where('integracao_xml.status', Integracao::XML_STATUS_NOT_INTEGRATED)
                ->where('users.inative', 0)
                ->where('users.level', '>=', IntegrationsQueues::NECESSARY_PRIORITY_LEVEL)
                ->where('integrations_queues.status', IntegrationsQueues::STATUS_PENDING)
                ->where('integrations_queues.priority', IntegrationsQueues::PRIORITY_LEVEL)
                ->where('integracao_xml.updated_at', '>=', $searchFrom)
                ->select('integracao_xml.*')
                ->orderBy('integracao_xml.updated_at', 'desc')
                ->limit(50) // Limite maior para integrações de nível (prioridade média)
                ->get();

            if ($integrations->isEmpty()) {
                $this->info('✅ Nenhuma integração de nível pendente encontrada');
                Cache::put($cacheKey, now()->toDateTimeString(), 3600);
                return 0;
            }

            $this->info("📊 Encontradas {$integrations->count()} integrações de nível pendentes");

            // Verificar jobs ativos na fila
            $activeJobs = DB::table('jobs')
                ->where('queue', 'level-integrations')
                ->count();

            $maxJobs = 2; // Máximo 2 jobs simultâneos para nível
            $availableSlots = max(0, $maxJobs - $activeJobs);

            if ($availableSlots === 0) {
                $this->warn("⚠️ Fila de nível ocupada ({$activeJobs}/{$maxJobs} jobs ativos)");
                return 0;
            }

            $this->info("🔄 Slots disponíveis: {$availableSlots}");

            // Processar integrações em lotes - Otimizado para evitar N+1
            $processed = 0;
            $chunkSize = min($availableSlots, 3); // Máximo 3 por execução
            $integrationIds = $integrations->take($chunkSize)->pluck('id')->toArray();

            // Verificar jobs existentes em uma única query - Otimizada com JSON_UNQUOTE
            $existingJobs = DB::table('jobs')
                ->where('queue', 'level-integrations')
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
                    ProcessIntegrationJob::dispatch($integration->id, 'level-integrations');
                    $processed++;

                    $this->line("✅ Integração {$integration->id} ({$integration->system}) despachada para fila de nível");

                } catch (\Exception $e) {
                    $this->error("❌ Erro ao processar integração {$integration->id}: " . $e->getMessage());
                    Log::error('AutomaticLevelIntegration error', [
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
            Log::error('AutomaticLevelIntegration fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
