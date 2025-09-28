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
use RuntimeException;
use Throwable;

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

        // Iniciar heartbeat
        $heartbeat = app(\App\Integracao\Application\Services\IntegrationHeartbeat::class);
        $heartbeat->startHeartbeat($integration->id);

        try {
            $heartbeat->updateHeartbeat($integration->id, 'validation', ['step' => 'validating_data']);
            $this->validateIntegration($integration);

            $heartbeat->updateHeartbeat($integration->id, 'fetch_xml', ['step' => 'downloading_xml']);
            $xmlContent = $this->fetchXmlContent($integration);

            $heartbeat->updateHeartbeat($integration->id, 'process_xml', ['step' => 'parsing_xml', 'xml_size' => strlen($xmlContent)]);
            $result = $this->processXmlContent($integration, $xmlContent);

            $executionTime = microtime(true) - $startTime;
            $this->metrics = [
                'execution_time' => $executionTime,
                'processed_items' => $result['processed_items'] ?? 0,
                'total_items' => $result['total_items'] ?? 0,
                'success_rate' => $result['total_items'] > 0 ?
                    round(($result['processed_items'] / $result['total_items']) * 100, 2) : 0
            ];

            $heartbeat->updateHeartbeat($integration->id, 'success', ['step' => 'completed', 'items_processed' => $result['processed_items'] ?? 0]);
            $this->logSuccess($integration, $this->metrics);

            // CRÍTICO: Validar se realmente processou imóveis
            $processedItems = $result['processed_items'] ?? 0;
            $totalItems = $result['total_items'] ?? 0;
            $hasProcessedItems = $processedItems > 0;

            if (!$hasProcessedItems) {
                Log::channel('integration')->error('⚠️ PROCESSING: No items were processed', [
                    'integration_id' => $integration->id,
                    'total_items' => $totalItems,
                    'processed_items' => $processedItems,
                    'execution_time' => $executionTime
                ]);
            }

            $response = [
                'success' => $hasProcessedItems,
                'processed_items' => $processedItems,
                'total_items' => $totalItems,
                'execution_time' => $executionTime,
                'metrics' => $this->metrics,
                'reason' => $hasProcessedItems ? 'items_processed' : 'no_items_processed'
            ];

        } catch (Throwable $e) {
            $executionTime = microtime(true) - $startTime;
            // Log detalhado do erro
            Log::channel('integration')->error('💥 PROCESSING: Critical error during integration', [
                'integration_id' => $integration->id,
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'execution_time' => $executionTime,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ]);

            // Atualizar heartbeat com erro
            try {
                $heartbeat->updateHeartbeat($integration->id, 'error', [
                    'step' => 'failed',
                    'error' => $e->getMessage(),
                    'error_type' => get_class($e)
                ]);
            } catch (\Exception $heartbeatError) {
                Log::channel('integration')->error('Failed to update heartbeat with error', [
                    'integration_id' => $integration->id,
                    'original_error' => $e->getMessage(),
                    'heartbeat_error' => $heartbeatError->getMessage()
                ]);
            }

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
            // CRÍTICO: Sempre parar heartbeat, mesmo em caso de exceção
            try {
                $status = isset($response) && $response['success'] ? 'completed' : 'failed';
                $heartbeat->stopHeartbeat($integration->id, $status);
            } catch (\Exception $heartbeatError) {
                Log::channel('integration')->error('💀 PROCESSING: CRITICAL - Failed to stop heartbeat', [
                    'integration_id' => $integration->id,
                    'heartbeat_error' => $heartbeatError->getMessage()
                ]);
            }

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
                return $cachedContent;
            }
        } catch (\Exception $e) {
            // Cache indisponível não deve interromper o fluxo
        }

        $maxRetries = 3;
        $retryDelay = 2000;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {


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
                        'max_redirects' => 5,
                        'curl' => [
                            CURLOPT_TCP_KEEPALIVE => 1,
                            CURLOPT_TCP_KEEPIDLE => 60,
                            CURLOPT_TCP_KEEPINTVL => 30,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_BUFFERSIZE => 8192,
                            CURLOPT_LOW_SPEED_LIMIT => 1024,
                            CURLOPT_LOW_SPEED_TIME => 60
                        ]
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
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $integration->link,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'ImovelGuide-Integration/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/xml, text/xml, */*',
                'Connection: keep-alive',
                'Cache-Control: no-cache'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 30,
            CURLOPT_BUFFERSIZE => 8192,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60
        ]);

        $xmlContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($xmlContent === false || !empty($error)) {
            throw new Exception("Fallback cURL request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new Exception("Fallback HTTP error {$httpCode}");
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
            libxml_use_internal_errors(true);

            $document = new Document();
            $document->load($xmlContent, false, Document::TYPE_XML,
                LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET);

            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            if (!empty($errors)) {
                $firstError = $errors[0];
                throw new RuntimeException(sprintf(
                    'XML parsing error (%s) at line %d column %d',
                    trim($firstError->message),
                    $firstError->line,
                    $firstError->column
                ));
            }

            // Heartbeat update
            $heartbeat = app(\App\Integracao\Application\Services\IntegrationHeartbeat::class);
            $heartbeat->updateHeartbeat($integration->id, 'factory_setup', ['step' => 'identifying_provider']);

            $factory = new XMLIntegrationsFactory();
            $factory->setIntegrationAndLoadXml($integration, $document);

            if (!$factory->hasProvider()) {
                throw new Exception("No provider found for XML structure");
            }

            $provider = $factory->getProvider();
            $providerClass = get_class($provider);



            $provider->setOptions([
                'isManual' => false,
                'isUpdate' => true,
                'updateType' => Integracao::XML_STATUS_IN_UPDATE_BOTH
            ]);

            $heartbeat->updateHeartbeat($integration->id, 'parsing', ['step' => 'parsing_with_provider', 'provider' => $providerClass]);
            $provider->parser();
            $heartbeat->updateHeartbeat($integration->id, 'prepare_data', ['step' => 'preparing_data']);
            $provider->prepareData();

            $totalItems = $provider->getImoveisCount();
            $heartbeat->updateHeartbeat($integration->id, 'insert_data', ['step' => 'inserting_data', 'total_items' => $totalItems]);

            $provider->insertData();

            $processedItems = $provider->getImoveisMade();

            $heartbeat->updateHeartbeat($integration->id, 'finalization', [
                'step' => 'completed_successfully',
                'processed_items' => $processedItems,
                'total_items' => $totalItems
            ]);

            return [
                'total_items' => $totalItems,
                'processed_items' => $processedItems
            ];

        } catch (Throwable $e) {
            $processingTime = microtime(true) - $stepStartTime;

            Log::channel('integration')->error('XML processing failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'xml_size' => strlen($xmlContent),
                'processing_time' => $processingTime,
                'memory_at_error' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ]);

            throw new RuntimeException('XML processing failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
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
        }

        Log::channel('integration')->info('Integration completed successfully', [
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
        }

        Log::channel('integration')->error('Integration processing failed', [
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
    }
}