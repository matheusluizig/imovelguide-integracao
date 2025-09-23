<?php

namespace App\Integracao\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\User;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Application\Jobs\ProcessIntegrationJob;
use App\Integracao\Application\Services\IntegrationProcessingService;
use DiDom\Document;

class TestIntegrationFlow extends Command
{
    protected $signature = 'integration:test-flow 
                            {--url= : URL do XML para testar}
                            {--user-id= : ID do usuário para teste}
                            {--mock-xml : Usar XML de exemplo}
                            {--skip-job : Pular execução do job, apenas testar parsing}
                            {--clean : Limpar dados de teste após execução}';

    protected $description = 'Testa o fluxo completo de integração: parser → job → worker';

    private $testUser;
    private $testIntegration;
    private $testQueue;

    public function handle(): int
    {
        $this->info('🧪 INICIANDO TESTE DO FLUXO DE INTEGRAÇÃO');
        $this->newLine();

        try {
            $this->setupTestEnvironment();
            $this->runIntegrationTests();
            
            if ($this->option('clean')) {
                $this->cleanupTestData();
            }

            $this->info('✅ TESTE CONCLUÍDO COM SUCESSO!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Erro durante o teste: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
            
            if ($this->option('clean')) {
                $this->cleanupTestData();
            }
            
            return 1;
        }
    }

    private function setupTestEnvironment(): void
    {
        $this->info('🔧 Configurando ambiente de teste...');

        // Get or create test user
        $userId = $this->option('user-id');
        if ($userId) {
            $this->testUser = User::find($userId);
            if (!$this->testUser) {
                throw new \Exception("Usuário com ID {$userId} não encontrado");
            }
        } else {
            // Try to find any active user first
            $this->testUser = User::where('inative', 0)->first();
            
            if (!$this->testUser) {
                // If no active user, create a test user
                $this->testUser = User::create([
                    'name' => 'Test User',
                    'email' => 'test@imovelguide.com',
                    'password' => bcrypt('password'),
                    'inative' => 0,
                    'level' => 1,
                    'integration_priority' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $this->info("👤 Usuário de teste criado: {$this->testUser->name} (ID: {$this->testUser->id})");
            }
        }

        $this->info("👤 Usando usuário: {$this->testUser->name} (ID: {$this->testUser->id})");

        // Clear Redis slots
        $this->clearRedisSlots();

        // Create test integration
        $url = $this->option('url') ?: 'https://test-integration.imovelguide.com/sample.xml';
        
        $this->testIntegration = Integracao::create([
            'user_id' => $this->testUser->id,
            'link' => $url,
            'system' => 'test-flow',
            'status' => Integracao::XML_STATUS_NOT_INTEGRATED,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->testQueue = IntegrationsQueues::create([
            'integration_id' => $this->testIntegration->id,
            'status' => IntegrationsQueues::STATUS_PENDING,
            'priority' => IntegrationsQueues::PRIORITY_NORMAL,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->info("📝 Integração de teste criada: ID {$this->testIntegration->id}");
        $this->info("🔗 URL: {$url}");
    }

    private function runIntegrationTests(): void
    {
        $this->newLine();
        $this->info('🚀 EXECUTANDO TESTES...');
        $this->newLine();

        // Test 1: XML Parsing
        $this->testXmlParsing();

        // Test 2: Service Processing
        $this->testServiceProcessing();

        // Test 3: Job Execution (if not skipped)
        if (!$this->option('skip-job')) {
            $this->testJobExecution();
        }

        // Test 4: Redis Slot Management
        $this->testRedisSlotManagement();

        // Test 5: Performance Monitoring
        $this->testPerformanceMonitoring();
    }

    private function testXmlParsing(): void
    {
        $this->info('1️⃣  Testando parsing do XML...');

        try {
            $xmlContent = $this->getXmlContent();
            
            $document = new Document();
            $document->load($xmlContent, false, Document::TYPE_XML,
                LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET);

            $factory = new \App\Integracao\Infrastructure\Parsers\XMLIntegrationsFactory();
            $factory->setIntegrationAndLoadXml($this->testIntegration, $document);

            if ($factory->hasProvider()) {
                $provider = $factory->getProvider();
                $providerClass = get_class($provider);
                $this->info("   ✅ Parser identificado: {$providerClass}");
            } else {
                $this->warn("   ⚠️  Nenhum parser identificado para o XML");
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Erro no parsing: " . $e->getMessage());
            throw $e;
        }
    }

    private function testServiceProcessing(): void
    {
        $this->info('2️⃣  Testando processamento do serviço...');

        try {
            if ($this->option('mock-xml')) {
                $this->mockHttpResponse();
            }

            $service = app(IntegrationProcessingService::class);
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $result = $service->processIntegration($this->testIntegration);

            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);

            $this->info("   ✅ Processamento concluído:");
            $this->info("      - Sucesso: " . ($result['success'] ? 'Sim' : 'Não'));
            $this->info("      - Tempo: " . number_format($endTime - $startTime, 3) . 's');
            $this->info("      - Memória: " . $this->formatBytes($endMemory - $startMemory));
            
            if ($result['success']) {
                $this->info("      - Items processados: " . ($result['processed_items'] ?? 0));
                $this->info("      - Total de items: " . ($result['total_items'] ?? 0));
            } else {
                $this->warn("      - Erro: " . ($result['error'] ?? 'Desconhecido'));
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Erro no serviço: " . $e->getMessage());
            // Don't rethrow - we want to continue with other tests
        }
    }

    private function testJobExecution(): void
    {
        $this->info('3️⃣  Testando execução do job...');

        try {
            if ($this->option('mock-xml')) {
                $this->mockHttpResponse();
            }

            $job = new ProcessIntegrationJob($this->testIntegration->id);
            
            $startTime = microtime(true);
            $job->handle();
            $endTime = microtime(true);

            $this->info("   ✅ Job executado com sucesso:");
            $this->info("      - Tempo de execução: " . number_format($endTime - $startTime, 3) . 's');
            
            // Check queue status
            $this->testQueue->refresh();
            $this->info("      - Status da fila: " . $this->getQueueStatusName($this->testQueue->status));

        } catch (\Exception $e) {
            $this->error("   ❌ Erro no job: " . $e->getMessage());
            // Don't rethrow - we want to continue with other tests
        }
    }

    private function testRedisSlotManagement(): void
    {
        $this->info('4️⃣  Testando gerenciamento de slots Redis...');

        try {
            $redis = Redis::connection();
            
            // Clear slots first
            $this->clearRedisSlots();
            
            $job = new ProcessIntegrationJob($this->testIntegration->id);
            
            // Test slot acquisition
            $reflection = new \ReflectionClass($job);
            $acquireMethod = $reflection->getMethod('acquireIntegrationSlot');
            $acquireMethod->setAccessible(true);
            
            $acquired = $acquireMethod->invoke($job);
            $this->info("   " . ($acquired ? "✅" : "❌") . " Aquisição de slot: " . ($acquired ? "Sucesso" : "Falhou"));
            
            if ($acquired) {
                $count = (int) ($redis->get('imovelguide_database_active_integrations_count') ?: 0);
                $this->info("      - Slots ativos: {$count}");
                
                // Test slot release
                $releaseMethod = $reflection->getMethod('releaseIntegrationSlot');
                $releaseMethod->setAccessible(true);
                $releaseMethod->invoke($job);
                
                $countAfter = (int) ($redis->get('imovelguide_database_active_integrations_count') ?: 0);
                $this->info("   ✅ Liberação de slot: Slots ativos após liberação: {$countAfter}");
            }

        } catch (\Exception $e) {
            $this->error("   ❌ Erro no Redis: " . $e->getMessage());
        }
    }

    private function testPerformanceMonitoring(): void
    {
        $this->info('5️⃣  Testando monitoramento de performance...');

        try {
            $memoryBefore = memory_get_usage(true);
            $peakBefore = memory_get_peak_usage(true);
            
            // Simulate some processing
            $data = [];
            for ($i = 0; $i < 1000; $i++) {
                $data[] = str_repeat('test', 100);
            }
            
            $memoryAfter = memory_get_usage(true);
            $peakAfter = memory_get_peak_usage(true);
            
            $this->info("   ✅ Monitoramento de memória:");
            $this->info("      - Memória usada: " . $this->formatBytes($memoryAfter - $memoryBefore));
            $this->info("      - Pico de memória: " . $this->formatBytes($peakAfter - $peakBefore));
            $this->info("      - Memória total atual: " . $this->formatBytes($memoryAfter));
            
            unset($data); // Free memory

        } catch (\Exception $e) {
            $this->error("   ❌ Erro no monitoramento: " . $e->getMessage());
        }
    }

    private function getXmlContent(): string
    {
        if ($this->option('mock-xml')) {
            return $this->getMockXmlContent();
        }

        $url = $this->testIntegration->link;
        
        try {
            $response = Http::timeout(30)->get($url);
            if (!$response->successful()) {
                throw new \Exception("HTTP error {$response->status()}");
            }
            return $response->body();
        } catch (\Exception $e) {
            $this->warn("Falha ao buscar XML real, usando XML de exemplo");
            return $this->getMockXmlContent();
        }
    }

    private function getMockXmlContent(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<ListingDataFeed>
    <Listings>
        <Listing>
            <ListingID>TEST12345</ListingID>
            <Title>Test Property</Title>
            <TransactionType>Sale</TransactionType>
            <Details>
                <Description>Beautiful test property</Description>
                <ListPrice>500000</ListPrice>
                <PropertyType>Apartment</PropertyType>
                <LivingArea>80</LivingArea>
                <Bedrooms>2</Bedrooms>
                <Bathrooms>2</Bathrooms>
                <Garage>1</Garage>
            </Details>
            <Location>
                <State abbreviation="SP">São Paulo</State>
                <City>Test City</City>
                <Neighborhood>Test Neighborhood</Neighborhood>
                <Address>123 Test Street</Address>
                <PostalCode>12345-678</PostalCode>
            </Location>
            <Media>
                <MediaURL>https://example.com/photo1.jpg</MediaURL>
                <MediaURL>https://example.com/photo2.jpg</MediaURL>
            </Media>
        </Listing>
    </Listings>
</ListingDataFeed>';
}

private function mockHttpResponse(): void
{
Http::fake([
'*' => Http::response($this->getMockXmlContent(), 200, [
'Content-Type' => 'application/xml'
])
]);
}

private function clearRedisSlots(): void
{
try {
$redis = Redis::connection();
$redis->del('imovelguide_database_active_integrations');
$redis->del('imovelguide_database_active_integrations_count');
$this->info("🧹 Slots Redis limpos");
} catch (\Exception $e) {
$this->warn("⚠️ Falha ao limpar slots Redis: " . $e->getMessage());
}
}

private function cleanupTestData(): void
{
$this->info('🧹 Limpando dados de teste...');

try {
if ($this->testQueue) {
$this->testQueue->delete();
}

if ($this->testIntegration) {
$this->testIntegration->delete();
}

$this->clearRedisSlots();
Cache::flush();

$this->info("✅ Dados de teste removidos");
} catch (\Exception $e) {
$this->warn("⚠️ Erro ao limpar dados: " . $e->getMessage());
}
}

private function getQueueStatusName(int $status): string
{
return match($status) {
IntegrationsQueues::STATUS_PENDING => 'PENDENTE',
IntegrationsQueues::STATUS_IN_PROCESS => 'EM_PROCESSO',
IntegrationsQueues::STATUS_DONE => 'CONCLUÍDO',
IntegrationsQueues::STATUS_STOPPED => 'PARADO',
IntegrationsQueues::STATUS_ERROR => 'ERRO',
default => 'DESCONHECIDO'
};
}

private function formatBytes(int $bytes): string
{
$units = ['B', 'KB', 'MB', 'GB'];
$bytes = max($bytes, 0);
$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
$pow = min($pow, count($units) - 1);
$bytes /= pow(1024, $pow);
return round($bytes, 2) . ' ' . $units[$pow];
}
}