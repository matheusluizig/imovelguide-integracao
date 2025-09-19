<?php

namespace App\Integracao\Application;

use App\Jobs\ProcessIntegrationChunkJob;
use App\Integracao;
use App\Models\IntegrationRun;
use App\Models\IntegrationRunChunk;
use Illuminate\Support\Facades\DB;

class IntegrationOrchestrator
{
    public function orchestrate(Integracao $integration): IntegrationRun
    {
        return DB::transaction(function () use ($integration) {
            $run = IntegrationRun::create([
                'integration_id' => $integration->id,
                'user_id' => $integration->user_id,
                'total_items' => 0,
                'processed_items' => 0,
                'status' => 'running',
            ]);

            $chunks = $this->createChunks($run);

            $maxParallel = (int) config('integration.max_parallel_chunks_per_integration', 3);
            $dispatched = 0;
            foreach ($chunks as $chunk) {
                ProcessIntegrationChunkJob::dispatch($run->id, $chunk->id)
                    ->onQueue('normal-integrations');
                $dispatched++;
                if ($dispatched % $maxParallel === 0) {
                    usleep(100000); // 100ms para evitar burst
                }
            }

            return $run;
        });
    }

    private function createChunks(IntegrationRun $run): array
    {
        $chunkSize = (int) config('integration.chunk_size', 200);
        $chunks = [];
        // Criamos 3 chunks iniciais; os próprios chunks param ao detectar fim do arquivo
        for ($i = 0; $i < 3; $i++) {
            $chunk = IntegrationRunChunk::create([
                'run_id' => $run->id,
                'offset' => $i * $chunkSize,
                'limit' => $chunkSize,
                'processed' => 0,
                'status' => 'pending'
            ]);
            $chunks[] = $chunk;
        }
        return $chunks;
    }
}


