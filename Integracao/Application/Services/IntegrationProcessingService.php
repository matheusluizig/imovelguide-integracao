<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Application\Services\XMLIntegrationLoggerService;
use App\Integracao\Infrastructure\Repositories\IntegrationRepository;
use App\Integracao\Infrastructure\Parsers\XMLIntegrationsFactory;
use DiDom\Document;
use Exception;

class IntegrationProcessingService
{
    private IntegrationRepository $repository;
    private ?XMLIntegrationLoggerService $logger = null;
    private array $metrics = [];

    public function __construct(
        IntegrationRepository $repository
    ) {
        $this->repository = $repository;
    }

    public function processIntegration(Integracao $integration): array
    {
        $startTime = microtime(true);
        $this->initializeLogger($integration);

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

            $response = [
                'success' => true,
                'processed_items' => $result['processed_items'] ?? 0,
                'total_items' => $result['total_items'] ?? 0,
                'execution_time' => $executionTime,
                'metrics' => $this->metrics
            ];

        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;
            $this->logError($integration, $e, $executionTime);

            $response = [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'error_type' => get_class($e),
                'error_code' => $e->getCode(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ];
        } finally {
            $this->logger = null;
        }

        return $response;
    }

    private function initializeLogger(Integracao $integration): void
    {
        $this->logger = new XMLIntegrationLoggerService($integration);
    }

    private function getLogger(): ?XMLIntegrationLoggerService
    {
        return $this->logger;
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

        
        $cleanUrl = trim($integration->link);
        if ($cleanUrl !== $integration->link) {
            Log::channel('integration')->warning("URL had extra spaces, cleaning", [
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

        
        try {
            $cachedContent = Cache::get($cacheKey);
            if ($cachedContent) {
                Log::channel('integration')->info("XML content loaded from cache", ['integration_id' => $integration->id]);
                return $cachedContent;
            }
        } catch (\Exception $e) {
            Log::channel('integration')->warning("Cache read failed, proceeding with HTTP request", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage()
            ]);
        }

        $maxRetries = 3;
        $retryDelay = 2000; 

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::channel('integration')->info("Fetching XML content", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'attempt' => $attempt
                ]);

                $response = Http::timeout(600) 
                    ->retry(2, 2000) 
                    ->withHeaders([
                        'User-Agent' => 'ImovelGuide-Integration/1.0',
                        'Accept' => 'application/xml, text/xml, */*',
                        'Connection' => 'keep-alive',
                        'Cache-Control' => 'no-cache'
                    ])
                    ->withOptions([
                        'verify' => false, 
                        'allow_redirects' => true,
                        'max_redirects' => 5
                    ])
                    ->get($integration->link);

                if (!$response->successful()) {
                    $errorMsg = "HTTP error {$response->status()}: {$response->body()}";

                    
                    if ($response->status() >= 400 && $response->status() < 500) {
                        $errorType = $response->status() === 404 ? 'URL not found' : 'Client error';
                        Log::channel('integration')->error("Integration {$errorType} ({$response->status()})", [
                            'integration_id' => $integration->id,
                            'url' => $integration->link,
                            'status' => $response->status(),
                            'error_body' => substr($response->body(), 0, 500)
                        ]);
                        throw new Exception("Request failed after 3 attempts: HTTP request returned status code {$response->status()}:\n" . substr($response->body(), 0, 500));
                    }

                    
                    if ($response->status() >= 500 && $attempt < $maxRetries) {
                        
                        $criticalErrors = ['Internal Server Error', 'Service Unavailable', 'Gateway Timeout'];
                        $isCriticalError = false;

                        foreach ($criticalErrors as $criticalError) {
                            if (strpos($response->body(), $criticalError) !== false) {
                                $isCriticalError = true;
                                break;
                            }
                        }

                        if ($isCriticalError && $attempt >= 2) {
                            Log::channel('integration')->error("Critical server error, not retrying", [
                                'integration_id' => $integration->id,
                                'status' => $response->status(),
                                'url' => $integration->link,
                                'attempt' => $attempt
                            ]);
                            throw new Exception($errorMsg);
                        }

                        Log::channel('integration')->warning("Server error, retrying", [
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
                    Log::channel('integration')->error("Empty XML content received", [
                        'integration_id' => $integration->id,
                        'url' => $integration->link,
                        'response_status' => $response->status(),
                        'response_headers' => $response->headers()
                    ]);
                    throw new Exception("Empty XML content received from URL: {$integration->link}");
                }

                
                $previousUseInternalErrors = libxml_use_internal_errors(true);
                $dom = new \DOMDocument();
                $dom->loadXML($xmlContent);
                $errors = libxml_get_errors();
                libxml_use_internal_errors($previousUseInternalErrors);

                if (!empty($errors)) {
                    Log::channel('integration')->warning("XML validation warnings", [
                        'integration_id' => $integration->id,
                        'warnings' => count($errors)
                    ]);
                }

                
                $this->storeXmlInCache($cacheKey, $xmlContent, $integration);

                Log::channel('integration')->info("XML content fetched successfully", [
                    'integration_id' => $integration->id,
                    'content_size' => strlen($xmlContent),
                    'attempt' => $attempt
                ]);

                return $xmlContent;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::channel('integration')->error("Connection error fetching XML", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt
                ]);

                if (Str::contains($e->getMessage(), 'cURL error 18')) {
                    try {
                        return $this->fetchXmlContentWithFallback($integration, $cacheKey, $attempt);
                    } catch (Exception $fallbackException) {
                        Log::channel('integration')->error("Fallback fetch failed", [
                            'integration_id' => $integration->id,
                            'url' => $integration->link,
                            'error' => $e->getMessage(),
                            'attempt' => $attempt,
                            'fallback_error' => $fallbackException->getMessage()
                        ]);
                    }
                }

                if ($attempt < $maxRetries) {
                    sleep($retryDelay / 1000);
                    $retryDelay *= 2;
                    continue;
                }

                throw new Exception("Connection failed after {$maxRetries} attempts: " . $e->getMessage());

            } catch (\Illuminate\Http\Client\RequestException $e) {
                Log::channel('integration')->error("Request error fetching XML", [
                    'integration_id' => $integration->id,
                    'url' => $integration->link,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt
                ]);

                if (Str::contains($e->getMessage(), 'cURL error 18')) {
                    try {
                        return $this->fetchXmlContentWithFallback($integration, $cacheKey, $attempt);
                    } catch (Exception $fallbackException) {
                        Log::channel('integration')->error("Fallback fetch failed", [
                            'integration_id' => $integration->id,
                            'url' => $integration->link,
                            'error' => $e->getMessage(),
                            'attempt' => $attempt,
                            'fallback_error' => $fallbackException->getMessage()
                        ]);
                    }
                }

                if ($attempt < $maxRetries) {
                    sleep($retryDelay / 1000);
                    $retryDelay *= 2;
                    continue;
                }

                throw new Exception("Request failed after {$maxRetries} attempts: " . $e->getMessage());

            } catch (Exception $e) {
                Log::channel('integration')->error("Failed to fetch XML content", [
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

    protected function fetchXmlContentWithFallback(Integracao $integration, string $cacheKey, int $attempt): string
    {
        Log::channel('integration')->warning("Attempting fallback fetch for XML content", [
            'integration_id' => $integration->id,
            'url' => $integration->link,
            'attempt' => $attempt
        ]);

        $xmlContent = $this->performFallbackDownload($integration);

        if (empty($xmlContent)) {
            throw new Exception("Fallback fetch returned empty content for URL: {$integration->link}");
        }

        $this->storeXmlInCache($cacheKey, $xmlContent, $integration);

        Log::channel('integration')->info("Fallback XML fetch succeeded", [
            'integration_id' => $integration->id,
            'url' => $integration->link,
            'content_size' => strlen($xmlContent),
            'attempt' => $attempt
        ]);

        return $xmlContent;
    }

    protected function performFallbackDownload(Integracao $integration): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: ImovelGuide-Integration/1.0\r\nAccept: application/xml, text/xml, */*",
                'timeout' => 600
            ]
        ]);

        $xmlContent = @file_get_contents($integration->link, false, $context);

        if ($xmlContent === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'Unknown error';
            throw new Exception("Fallback HTTP request failed: {$message}");
        }

        return $xmlContent;
    }

    private function storeXmlInCache(string $cacheKey, string $xmlContent, Integracao $integration): void
    {
        try {
            Cache::put($cacheKey, $xmlContent, 3600);
        } catch (\Exception $e) {
            Log::channel('integration')->warning("Cache write failed, continuing without cache", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function processXmlContent(Integracao $integration, string $xmlContent): array
    {
        $stepStartTime = microtime(true);
        
        try {
            Log::channel('integration')->info("Starting XML document parsing", [
                'integration_id' => $integration->id,
                'xml_size' => strlen($xmlContent),
                'memory_before' => memory_get_usage(true)
            ]);

            $document = new Document();
            $document->load($xmlContent, false, Document::TYPE_XML,
                LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET);

            $parseTime = microtime(true) - $stepStartTime;
            Log::channel('integration')->info("XML document parsed successfully", [
                'integration_id' => $integration->id,
                'parse_time' => $parseTime,
                'memory_after_parse' => memory_get_usage(true)
            ]);

            $factory = new XMLIntegrationsFactory();
            $factory->setIntegrationAndLoadXml($integration, $document);

            if (!$factory->hasProvider()) {
                throw new Exception("No provider found for XML structure");
            }

            $provider = $factory->getProvider();
            $providerClass = get_class($provider);
            
            Log::channel('integration')->info("Provider identified", [
                'integration_id' => $integration->id,
                'provider' => $providerClass
            ]);

            $provider->setOptions([
                'isManual' => false,
                'isUpdate' => true,
                'updateType' => Integracao::XML_STATUS_IN_UPDATE_BOTH
            ]);

            $parserStartTime = microtime(true);
            $provider->parser();
            $parserTime = microtime(true) - $parserStartTime;
            
            Log::channel('integration')->info("XML parsed by provider", [
                'integration_id' => $integration->id,
                'parser_time' => $parserTime,
                'memory_after_parser' => memory_get_usage(true)
            ]);

            $prepareStartTime = microtime(true);
            $provider->prepareData();
            $prepareTime = microtime(true) - $prepareStartTime;
            
            Log::channel('integration')->info("Data prepared", [
                'integration_id' => $integration->id,
                'prepare_time' => $prepareTime,
                'memory_after_prepare' => memory_get_usage(true)
            ]);

            $insertStartTime = microtime(true);
            $provider->insertData();
            $insertTime = microtime(true) - $insertStartTime;

            $totalItems = $provider->getImoveisCount();
            $processedItems = $provider->getImoveisMade();

            Log::channel('integration')->info("XML processing completed successfully", [
                'integration_id' => $integration->id,
                'total_items' => $totalItems,
                'processed_items' => $processedItems,
                'insert_time' => $insertTime,
                'total_processing_time' => microtime(true) - $stepStartTime,
                'memory_final' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ]);

            return [
                'total_items' => $totalItems,
                'processed_items' => $processedItems
            ];

        } catch (Exception $e) {
            $processingTime = microtime(true) - $stepStartTime;
            
            Log::channel('integration')->error("XML processing failed", [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'xml_size' => strlen($xmlContent),
                'processing_time' => $processingTime,
                'memory_at_error' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("XML processing failed: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    private function logSuccess(Integracao $integration, array $metrics): void
    {
        $logger = $this->getLogger();
        if ($logger) {
            $logger->loggerDone(
                $metrics['total_items'],
                $metrics['processed_items'],
                "Integration processed successfully in {$metrics['execution_time']}s"
            );
        } else {
            Log::channel('integration')->warning('Integration logger not available to log success', [
                'integration_id' => $integration->id,
                'user_id' => $integration->user_id,
            ]);
        }

        Log::channel('integration')->info("Integration completed successfully", [
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'system' => $integration->system ?? 'unknown',
            'metrics' => $metrics
        ]);
    }

    private function logError(Integracao $integration, Exception $e, float $executionTime): void
    {
        $logger = $this->getLogger();
        if ($logger) {
            $logger->loggerErrWarn($e->getMessage());
        } else {
            Log::channel('integration')->warning('Integration logger not available to log error', [
                'integration_id' => $integration->id,
                'user_id' => $integration->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('integration')->error("Integration processing failed", [
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

        Log::channel('integration')->info("Cache cleared for integration", ['integration_id' => $integrationId]);
    }
}