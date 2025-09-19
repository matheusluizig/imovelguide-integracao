<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Infrastructure\Parsers\XMLIntegrationsFactory;
use App\Integracao\Application\Services\XMLIntegrationLoggerService;
use App\Integracao\Infrastructure\Repositories\IntegrationRepository;
use DiDom\Document;
use Exception;

class IntegrationProcessingService
{
    private IntegrationRepository $repository;
    private XMLIntegrationLoggerService $logger;
    private array $metrics = [];

    public function __construct(
        IntegrationRepository $repository,
        XMLIntegrationLoggerService $logger
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    public function processIntegration(Integracao $integration): array
    {
        $startTime = microtime(true);

        try {
            $this->validateIntegration($integration);
            $xmlContent = $this->fetchXmlContent($integration);
            $result = $this->processXmlContent($integration, $xmlContent);

            $executionTime = microtime(true) - $startTime;
            $this->metrics = [
                'execution_time' => $executionTime,
                'processed_items' => $result['processed_items'] ?? 0,
                'total_items' => $result['total_items'] ?? 0,
                'success_rate' => $result['total_items'] > 0 ?
                    round(($result['processed_items'] / $result['total_items']) * 100, 2) : 0
            ];

            $this->logSuccess($integration, $this->metrics);

            return [
                'success' => true,
                'processed_items' => $result['processed_items'] ?? 0,
                'total_items' => $result['total_items'] ?? 0,
                'execution_time' => $executionTime,
                'metrics' => $this->metrics
            ];

        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;
            $this->logError($integration, $e, $executionTime);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ];
        }
    }

    private function validateIntegration(Integracao $integration): void
    {
        if (!$integration->user) {
            throw new Exception("User not found for integration {$integration->id}");
        }

        if ($integration->user->inative == 1) {
            throw new Exception("User is inactive for integration {$integration->id}");
        }

        if (empty($integration->link)) {
            throw new Exception("Integration link is empty for integration {$integration->id}");
        }

        // Limpar espaços extras da URL
        $cleanUrl = trim($integration->link);
        if ($cleanUrl !== $integration->link) {
            Log::warning("URL had extra spaces, cleaning", [
                'integration_id' => $integration->id,
                'original_url' => $integration->link,
                'cleaned_url' => $cleanUrl
            ]);
            $integration->link = $cleanUrl;
            $integration->save();
        }

        if (!filter_var($integration->link, FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid URL format for integration {$integration->id}: {$integration->link}");
        }
    }

    private function fetchXmlContent(Integracao $integration): string
    {
        $cacheKey = "xml_content_{$integration->id}_" . md5($integration->link);

        // Tentar buscar do cache com fallback
        try {
            $cachedContent = Cache::get($cacheKey);
            if ($cachedContent) {
                Log::info("XML content loaded from cache", ['integration_id' => $integration->id]);
                return $cachedContent;
            }
        } catch (\Exception $e) {
            Log::warning("Cache read failed, proceeding with HTTP request", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage()
            ]);
        }

        $maxRetries = 3;
        $retryDelay = 2000; // 2 segundos

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info("Fetching XML content", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'attempt' => $attempt
                ]);

                $response = Http::timeout(600) // 10 minutos para download de XML
                    ->retry(2, 2000) // 2 tentativas com 2 segundos de delay
                    ->withHeaders([
                        'User-Agent' => 'ImovelGuide-Integration/1.0',
                        'Accept' => 'application/xml, text/xml, */*',
                        'Connection' => 'keep-alive',
                        'Cache-Control' => 'no-cache'
                    ])
                    ->withOptions([
                        'verify' => false, // Para URLs com SSL problemático
                        'allow_redirects' => true,
                        'max_redirects' => 5
                    ])
                    ->get($integration->link);

                if (!$response->successful()) {
                    $errorMsg = "HTTP error {$response->status()}: {$response->body()}";

                    // Se for erro 4xx, não tentar novamente
                    if ($response->status() >= 400 && $response->status() < 500) {
                        $errorType = $response->status() === 404 ? 'URL not found' : 'Client error';
                        Log::error("Integration {$errorType} ({$response->status()})", [
                            'integration_id' => $integration->id,
                            'url' => $integration->link,
                            'status' => $response->status(),
                            'error_body' => substr($response->body(), 0, 500)
                        ]);
                        throw new Exception("Request failed after 3 attempts: HTTP request returned status code {$response->status()}:\n" . substr($response->body(), 0, 500));
                    }

                    // Se for erro 5xx, tentar novamente apenas se não for erro crítico
                    if ($response->status() >= 500 && $attempt < $maxRetries) {
                        // Verificar se é um erro crítico que não deve ser retentado
                        $criticalErrors = ['Internal Server Error', 'Service Unavailable', 'Gateway Timeout'];
                        $isCriticalError = false;

                        foreach ($criticalErrors as $criticalError) {
                            if (strpos($response->body(), $criticalError) !== false) {
                                $isCriticalError = true;
                                break;
                            }
                        }

                        if ($isCriticalError && $attempt >= 2) {
                            Log::error("Critical server error, not retrying", [
                                'integration_id' => $integration->id,
                                'status' => $response->status(),
                                'url' => $integration->link,
                                'attempt' => $attempt
                            ]);
                            throw new Exception($errorMsg);
                        }

                        Log::warning("Server error, retrying", [
                            'integration_id' => $integration->id,
                            'status' => $response->status(),
                            'attempt' => $attempt
                        ]);
                        sleep($retryDelay / 1000);
                        $retryDelay *= 2;
                        continue;
                    }

                    throw new Exception($errorMsg);
                }

                $xmlContent = $response->body();

                if (empty($xmlContent)) {
                    Log::error("Empty XML content received", [
                        'integration_id' => $integration->id,
                        'url' => $integration->link,
                        'response_status' => $response->status(),
                        'response_headers' => $response->headers()
                    ]);
                    throw new Exception("Empty XML content received from URL: {$integration->link}");
                }

                // Validar se é XML válido
                $previousUseInternalErrors = libxml_use_internal_errors(true);
                $dom = new \DOMDocument();
                $dom->loadXML($xmlContent);
                $errors = libxml_get_errors();
                libxml_use_internal_errors($previousUseInternalErrors);

                if (!empty($errors)) {
                    Log::warning("XML validation warnings", [
                        'integration_id' => $integration->id,
                        'warnings' => count($errors)
                    ]);
                }

                // Tentar salvar no cache com fallback
                try {
                    Cache::put($cacheKey, $xmlContent, 3600);
                } catch (\Exception $e) {
                    Log::warning("Cache write failed, continuing without cache", [
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage()
                    ]);
                }

                Log::info("XML content fetched successfully", [
                    'integration_id' => $integration->id,
                    'content_size' => strlen($xmlContent),
                    'attempt' => $attempt
                ]);

                return $xmlContent;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("Connection error fetching XML", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt
                ]);

                if ($attempt < $maxRetries) {
                    sleep($retryDelay / 1000);
                    $retryDelay *= 2;
                    continue;
                }

                throw new Exception("Connection failed after {$maxRetries} attempts: " . $e->getMessage());

            } catch (\Illuminate\Http\Client\RequestException $e) {
                Log::error("Request error fetching XML", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt
                ]);

                if ($attempt < $maxRetries) {
                    sleep($retryDelay / 1000);
                    $retryDelay *= 2;
                    continue;
                }

                throw new Exception("Request failed after {$maxRetries} attempts: " . $e->getMessage());

            } catch (Exception $e) {
                Log::error("Failed to fetch XML content", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt
                ]);

                if ($attempt < $maxRetries) {
                    sleep($retryDelay / 1000);
                    $retryDelay *= 2;
                    continue;
                }

                throw new Exception("Failed to fetch XML after {$maxRetries} attempts: " . $e->getMessage());
            }
        }

        throw new Exception("Failed to fetch XML content after all retries");
    }

    private function processXmlContent(Integracao $integration, string $xmlContent): array
    {
        try {
            $document = new Document();
            $document->load($xmlContent, false, Document::TYPE_XML,
                LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET);

            $factory = new XMLIntegrationsFactory();
            $factory->setIntegrationAndLoadXml($integration, $document);

            if (!$factory->hasProvider()) {
                throw new Exception("No provider found for XML structure");
            }

            $provider = $factory->getProvider();

            $provider->setOptions([
                'isManual' => false,
                'isUpdate' => true,
                'updateType' => Integracao::XML_STATUS_IN_UPDATE_BOTH
            ]);

            $provider->parser();
            $provider->prepareData();
            $provider->insertData();

            $totalItems = $provider->getImoveisCount();
            $processedItems = $provider->getImoveisMade();

            Log::info("XML processing completed", [
                'integration_id' => $integration->id,
                'total_items' => $totalItems,
                'processed_items' => $processedItems
            ]);

            return [
                'total_items' => $totalItems,
                'processed_items' => $processedItems
            ];

        } catch (Exception $e) {
            Log::error("XML processing failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'xml_size' => strlen($xmlContent)
            ]);
            throw new Exception("XML processing failed: " . $e->getMessage());
        }
    }

    private function logSuccess(Integracao $integration, array $metrics): void
    {
        $this->logger->loggerDone(
            $metrics['total_items'],
            $metrics['processed_items'],
            "Integration processed successfully in {$metrics['execution_time']}s"
        );

        Log::info("Integration completed successfully", [
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'system' => $integration->system ?? 'unknown',
            'metrics' => $metrics
        ]);
    }

    private function logError(Integracao $integration, Exception $e, float $executionTime): void
    {
        $this->logger->loggerErrWarn($e->getMessage());

        Log::error("Integration processing failed", [
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'system' => $integration->system ?? 'unknown',
            'error' => $e->getMessage(),
            'execution_time' => $executionTime
        ]);
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function clearCache(int $integrationId): void
    {
        $cacheKey = "xml_content_{$integrationId}_*";
        Cache::forget($cacheKey);

        Log::info("Cache cleared for integration", ['integration_id' => $integrationId]);
    }
}