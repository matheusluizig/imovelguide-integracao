<?php

declare(strict_types=1);

use App\Integracao\Application\Services\IntegrationProcessingService;
use App\Integracao\Domain\Entities\Integracao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/Stubs/bootstrap.php';
require __DIR__ . '/../Integracao/Application/Services/IntegrationProcessingService.php';

class TestIntegrationProcessingService extends IntegrationProcessingService
{
    /** @var string[] */
    public array $fallbackResponses = [];

    protected function performFallbackDownload(Integracao $integration): string
    {
        if (empty($this->fallbackResponses)) {
            return parent::performFallbackDownload($integration);
        }

        return array_shift($this->fallbackResponses);
    }
}

function callFetchXml(IntegrationProcessingService $service, Integracao $integration): string
{
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('fetchXmlContent');
    $method->setAccessible(true);

    return $method->invoke($service, $integration);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeIntegration(int $id, string $link): Integracao
{
    $integration = new Integracao();
    $integration->id = $id;
    $integration->link = $link;
    $integration->user = (object) ['inative' => 0];

    return $integration;
}

function runFallbackScenario(string $exceptionClass, string $expectedXml, string $label): void
{
    Cache::reset();
    Log::reset();
    Http::setSequence([new $exceptionClass('cURL error 18: transfer closed')]);

    $repository = new App\Integracao\Infrastructure\Repositories\IntegrationRepository();
    $logger = new App\Integracao\Application\Services\XMLIntegrationLoggerService();

    $service = new TestIntegrationProcessingService($repository, $logger);
    $service->fallbackResponses[] = $expectedXml;

    $integration = makeIntegration($exceptionClass === ConnectionException::class ? 1 : 2, 'https://example.com/feed.xml');

    $result = callFetchXml($service, $integration);

    assertTrue($result === $expectedXml, sprintf('%s: expected fallback XML to be returned', $label));

    $cacheKey = "xml_content_{$integration->id}_" . md5($integration->link);
    assertTrue(Cache::get($cacheKey) === $expectedXml, sprintf('%s: fallback XML was not cached', $label));

    $attemptLogs = array_filter(Log::$logs, fn ($log) => $log['message'] === 'Attempting fallback fetch for XML content');
    assertTrue(count($attemptLogs) === 1, sprintf('%s: fallback attempt was not logged', $label));

    $successLogs = array_filter(Log::$logs, fn ($log) => $log['message'] === 'Fallback XML fetch succeeded');
    assertTrue(count($successLogs) === 1, sprintf('%s: fallback success was not logged', $label));
}

runFallbackScenario(ConnectionException::class, '<xml>connection</xml>', 'ConnectionException scenario');
runFallbackScenario(RequestException::class, '<xml>request</xml>', 'RequestException scenario');

echo "All fallback scenarios passed.\n";
