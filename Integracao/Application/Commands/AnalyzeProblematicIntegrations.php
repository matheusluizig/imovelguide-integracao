<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use Illuminate\Support\Facades\Http;
use DiDom\Document;

class AnalyzeProblematicIntegrations extends Command
{
    protected $signature = 'integration:analyze-problematic {--limit=5 : Number of integrations to analyze}';
    protected $description = 'Analisa integrações que estão falhando para identificar problemas';

    public function handle()
    {
        $limit = $this->option('limit');
        
        $this->info("Analisando {$limit} integrações problemáticas...");

        
        $failedIntegrations = IntegrationsQueues::where('status', 3)
            ->where('updated_at', '>', now()->subHours(24))
            ->orderBy('updated_at', 'desc')
            ->take($limit)
            ->get();

        if ($failedIntegrations->isEmpty()) {
            $this->info('Nenhuma integração problemática encontrada.');
            return;
        }

        foreach ($failedIntegrations as $queue) {
            $this->analyzeIntegration($queue);
        }
    }

    private function analyzeIntegration($queue)
    {
        $integration = Integracao::find($queue->integration_id);
        if (!$integration) {
            $this->error("Integração {$queue->integration_id} não encontrada.");
            return;
        }

        $this->info("\n=== ANÁLISE DA INTEGRAÇÃO ID: {$integration->id} ===");
        $this->info("Link: {$integration->link}");
        $this->info("User: {$integration->user->name}");
        $this->info("System: {$integration->system}");
        $this->info("Error: {$queue->error_message}");
        $this->info("Step: {$queue->last_error_step}");

        try {
            
            $this->info("\n--- Testando Download ---");
            $response = Http::timeout(30)->get($integration->link);
            
            $this->info("Status: {$response->status()}");
            $this->info("Content Length: " . strlen($response->body()));
            $this->info("Content Type: " . $response->header('Content-Type'));

            if (empty($response->body())) {
                $this->error("❌ XML vazio recebido");
                return;
            }

            
            $this->info("\n--- Testando Parsing XML ---");
            $document = new Document();
            $document->load($response->body(), false, Document::TYPE_XML);
            
            $rootElement = $document->getDocument()->documentElement;
            if (!$rootElement) {
                $this->error("❌ Elemento raiz não encontrado");
                return;
            }

            $this->info("Root Element: {$rootElement->nodeName}");

            $childElements = [];
            foreach ($rootElement->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $childElements[] = $child->nodeName;
                }
            }

            $this->info("Child Elements: " . implode(', ', array_slice($childElements, 0, 10)));
            if (count($childElements) > 10) {
                $this->info("... e mais " . (count($childElements) - 10) . " elementos");
            }

            
            $this->info("\n--- Verificando Provedores ---");
            $providers = [
                'ListingDataFeed + Listings' => $document->has('ListingDataFeed') && $document->has('Listings'),
                'ListingDataFeed + Properties' => $document->has('ListingDataFeed') && $document->has('Properties'),
                'Union' => $document->has('Union'),
                'publish + properties' => $document->has('publish') && $document->has('properties'),
                'Carga' => $document->has('Carga'),
                'Anuncios' => $document->has('Anuncios'),
                'imobibrasil' => $document->has('imobibrasil'),
                'OpenNavent' => $document->has('OpenNavent'),
                'ad' => $document->has('ad')
            ];

            $foundProvider = false;
            foreach ($providers as $name => $hasProvider) {
                if ($hasProvider) {
                    $this->info("✅ Provedor encontrado: {$name}");
                    $foundProvider = true;
                }
            }

            if (!$foundProvider) {
                $this->error("❌ Nenhum provedor reconhece esta estrutura XML");
                $this->info("Estrutura completa: " . $this->getXmlStructureInfo($document));
            }

        } catch (\Exception $e) {
            $this->error("❌ Erro durante análise: " . $e->getMessage());
        }
    }

    private function getXmlStructureInfo($document)
    {
        try {
            $rootElement = $document->getDocument()->documentElement;
            if (!$rootElement) {
                return 'Root element not found';
            }

            $rootName = $rootElement->nodeName;
            $childElements = [];
            
            foreach ($rootElement->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $childElements[] = $child->nodeName;
                }
            }

            $childInfo = empty($childElements) ? 'no children' : implode(', ', array_slice($childElements, 0, 5));
            if (count($childElements) > 5) {
                $childInfo .= '... (+' . (count($childElements) - 5) . ' more)';
            }

            return "Root: {$rootName}, Children: {$childInfo}";
        } catch (\Exception $e) {
            return 'Structure analysis failed: ' . $e->getMessage();
        }
    }
}
