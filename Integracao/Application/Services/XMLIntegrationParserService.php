<?php

namespace App\Integracao\Application\Services;

use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;
use App\Integracao\Infrastructure\Parsers\XMLIntegrationsFactory;
use App\Integracao\Application\Services\XMLIntegrationLoggerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Integracao\Infrastructure\Helpers\IntegrationHelper;
use Exception;
use Throwable;

class XMLIntegrationParserService
{
  private $integration = null;
  private $factory = null;
  private $logger = null;
  private $discordLogger;
  public function __construct(XMLIntegrationsFactory $factory)
  {
    $this->factory = $factory;
    $this->discordLogger = Log::channel('integration');
  }

  public function getProvider()
  {
    return $this->factory->getProvider();
  }

  public function setIntegration(Integracao $integration): void
  {
    $this->integration = $integration;
  }

  





  public function setLoggerService(XMLIntegrationLoggerService $service): void
  {
    $this->logger = $service;
  }

  




  private function parseIntegration(): bool
  {
    if (!$this->integrationHasValidLink()) {
      $this->logError('Link de XML inválido', [
        'id_integracao' => $this->integration->id,
        'link' => $this->integration->link,
      ]);

      if ($this->logger) {
        $this->logger->loggerErrWarn('Link de XML invalido.');
      }
      return false;
    }

    if (!$this->integrationHasValidXml()) {
      $this->logError('XML inválido ou inacessível', [
        'id_integracao' => $this->integration->id,
        'link' => $this->integration->link,
      ]);

      return false;
    }

    return true;
  }

  




  private function canParseXml(): bool
  {
    if (!$this->parseIntegration()) {
      return false; 
    }

    $this->factory->setIntegrationAndLoadXml($this->integration);

    if (!$this->factory->hasProvider()) {
      $this->logError('Provedor não encontrado para o XML', [
        'id_integracao' => $this->integration->id,
        'link' => $this->integration->link,
        'tipo' => $this->integration->type ?? 'não especificado',
      ]);

      if ($this->logger) {
        $this->logger->loggerErrWarn('Provedor Não encontrado.');
      }
      return false;
    }

    return true;
  }

  




  public function integrationHasValidLink(): bool
  {
    $link = IntegrationHelper::loadSafeLink($this->integration->link);
    $isValid = filter_var($link, FILTER_VALIDATE_URL) !== false;

    if (!$isValid) {
      $this->logError('Link malformado', [
        'id_integracao' => $this->integration->id,
        'link_original' => $this->integration->link,
        'link_processado' => $link,
      ]);
    }

    return $isValid;
  }

  





  public function searchXmlInLink(array $fileType): bool
  {
    if (stripos($fileType[0], 'application/xml') === false && stripos($fileType[0], 'text/xml') === false) {
      $this->logError('Content-Type inválido', [
        'id_integracao' => $this->integration->id,
        'content_type' => $fileType[0],
        'link' => $this->integration->link,
      ]);

      if ($this->logger) {
        $this->logger->loggerErrWarn(
          'Verifique o Http::get() no local pois não foi encontrado um "aplicativo/xml" nem "text/xml" no cabeçalho, verifique se exista algum dado xml ou se o link é realmente inválido.'
        );
      }

      return false;
    }

    return true;
  }

  




  public function integrationHasValidXml(): bool
  {
    $maxRetries = 3;
    $retryDelay = 10; 

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
      try {
        $response = $this->requestXml();

        if ($response && $response->getStatusCode() === 200) {
          if ($this->searchXmlInLink($response->getHeader('Content-Type'))) {
            $this->factory->setResponse($response);
            return true;
          }
          return false;
        }
      } catch (Throwable $th) {
        $this->logError("Erro ao acessar XML (Tentativa {$attempt}/{$maxRetries})", [
          'id_integracao' => $this->integration->id,
          'link' => $this->integration->link,
          'erro' => $th->getMessage(),
          'codigo' => $th->getCode(),
        ]);

        if ($attempt < $maxRetries) {
          sleep($retryDelay);
          $retryDelay *= 2; 
          continue;
        }

        $response = null;
      }
    }

    if (!$response || $response->getStatusCode() !== 200) {
      $this->logError("Falha na requisição HTTP após {$maxRetries} tentativas", [
        'id_integracao' => $this->integration->id,
        'link' => $this->integration->link,
        'status_code' => $response ? $response->getStatusCode() : 'sem resposta',
        'headers' => $response ? $response->headers() : [],
      ]);

      if ($this->logger) {
        $this->logger->loggerErrWarn('Não foi possível abrir o Link no "Http::get()", o link talvez seja inválido.');
      }
      return false;
    }

    return false;
  }

  




  private function requestXml()
  {
    return Http::withUserAgent('ImovelGuide/1.0 (XML Integration Service; +https://imovelguide.com.br)')
      ->timeout(600) 
      ->get(IntegrationHelper::loadSafeLink($this->integration->link));
  }

  






  public function doIntegration(Integracao $integration, array $options = []): bool
  {
    try {
      Log::channel('integration')->info("🔧 doIntegration() iniciado", [
        'integration_id' => $integration->id,
        'integration_name' => $integration->system ?? 'sem_nome',
        'integration_link' => $integration->link ?? 'sem_link'
      ]);

      if (!$this->canDoIntegration($integration, $options)) {
        Log::channel('integration')->warning("❌ canDoIntegration() retornou false", [
          'integration_id' => $integration->id,
          'integration_name' => $integration->system ?? 'sem_nome'
        ]);
        return false;
      }

      Log::channel('integration')->info("✅ canDoIntegration() passou - definindo integração");
      $this->setIntegration($integration);

      if (!$this->canParseXml()) {
        Log::channel('integration')->warning("❌ canParseXml() retornou false", [
          'integration_id' => $integration->id,
          'integration_name' => $integration->system ?? 'sem_nome'
        ]);
        $this->logError('Falha ao validar XML', [
          'id_integracao' => $integration->id,
          'link_xml' => $integration->link,
        ]);
        return false;
      }

      Log::channel('integration')->info("✅ canParseXml() passou - configurando provider");
      $this->getProvider()->setOptions($options);

      Log::channel('integration')->info("🚀 Executando steps da integração");
      if (!$this->executeIntegrationSteps()) {
        Log::channel('integration')->warning("❌ executeIntegrationSteps() retornou false", [
          'integration_id' => $integration->id,
          'integration_name' => $integration->system ?? 'sem_nome'
        ]);
        return false;
      }

      Log::channel('integration')->info("🎉 doIntegration() concluído com sucesso", [
        'integration_id' => $integration->id,
        'integration_name' => $integration->system ?? 'sem_nome'
      ]);
      return true;
    } catch (Exception $e) {
      $this->logError('Erro não tratado na integração', [
        'id_integracao' => $integration->id,
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
      ]);
      return false;
    }
  }

  






  public function canDoIntegration(Integracao $integration, array $options = []): bool
  {
    $lastCheckIntegration = Integracao::with('queue')->where('id', $integration->id)->first();

    if ($lastCheckIntegration && (!isset($options['isManual']) || !$options['isManual'])) {
      if ($this->integrationIsInProgress($lastCheckIntegration)) {
        return false;
      }
    }

    return true;
  }

  





  private function integrationIsInProgress(Integracao $integration): bool
  {
    // Verifica se a integração XML está em processamento
    if (
      $integration->status == Integracao::XML_STATUS_IN_UPDATE_BOTH ||
      $integration->status == Integracao::XML_STATUS_IN_DATA_UPDATE ||
      $integration->status == Integracao::XML_STATUS_IN_IMAGE_UPDATE
    ) {
      return true;
    }

    // Verifica se há uma fila em processo (removido STATUS_DONE para permitir reprocessamento)
    if ($integration->queue && $integration->queue->status == IntegrationsQueues::STATUS_IN_PROCESS) {
      return true;
    }

    return false;
  }

  




  private function executeIntegrationSteps(): bool
  {
    if (!$this->executeProviderParser()) {
      return false;
    }

    if (!$this->executeProviderPrepareData()) {
      return false;
    }

    if (!$this->executeProviderInsertData()) {
      return false;
    }

    return true;
  }

  




  private function executeProviderParser(): bool
  {
    try {
      $this->getProvider()->parser();
      return true;
    } catch (Exception $e) {
      $this->logError('Erro no parser do XML', [
        'id_integracao' => $this->integration->id,
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
      ]);
      return false;
    }
  }

  




  private function executeProviderPrepareData(): bool
  {
    try {
      $this->getProvider()->prepareData();
      return true;
    } catch (Exception $e) {
      $this->logError('Erro ao preparar dados', [
        'id_integracao' => $this->integration->id,
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
      ]);
      return false;
    }
  }

  




  private function executeProviderInsertData(): bool
  {
    try {
      $this->getProvider()->insertData();
      return true;
    } catch (Exception $e) {
      $this->logError('Erro ao inserir dados', [
        'id_integracao' => $this->integration->id,
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
      ]);
      return false;
    }
  }

  





  public function deleteRelatedRecords($anuncio): void
  {
    
    DB::table('anuncio_images')->where('anuncio_id', $anuncio->id)->delete();
    DB::table('anuncio_beneficio')->where('anuncio_id', $anuncio->id)->delete();
    DB::table('anuncio_enderecos')->where('anuncio_id', $anuncio->id)->delete();
    DB::table('lista_corretores_da_construtora')->where('anuncio_id', $anuncio->id)->delete();
    
    $anuncio->delete();
  }

  






  private function logError(string $message, array $context = []): void
  {
    $this->discordLogger->error($message, $context);
  }
}