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
    $this->discordLogger = Log::channel('discord_integration');
  }

  public function getProvider()
  {
    return $this->factory->getProvider();
  }

  /**
   * Define a integração a ser processada
   *
   * @param Integracao $integration
   * @return void
   */
  public function setIntegration(Integracao $integration): void
  {
    $this->integration = $integration;
  }

  /**
   * Define o serviço de logging
   *
   * @param XMLIntegrationLoggerService $service
   * @return void
   */
  public function setLoggerService(XMLIntegrationLoggerService $service): void
  {
    $this->logger = $service;
  }

  /**
   * Verifica e parseia a integração
   *
   * @return bool
   */
  private function parseIntegration(): bool
  {
    if (!$this->integrationHasValidLink()) {
      $this->logError('Link de XML inválido', [
        'id_integracao' => $this->integration->id,
        'link' => $this->integration->link,
      ]);

      $this->logger->loggerErrWarn('Link de XML invalido.');
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

  /**
   * Verifica se o XML pode ser parseado
   *
   * @return bool
   */
  private function canParseXml(): bool
  {
    if (!$this->parseIntegration()) {
      return false; // Erro já foi registrado
    }

    $this->factory->setIntegrationAndLoadXml($this->integration);

    if (!$this->factory->hasProvider()) {
      $this->logError('Provedor não encontrado para o XML', [
        'id_integracao' => $this->integration->id,
        'link' => $this->integration->link,
        'tipo' => $this->integration->type ?? 'não especificado',
      ]);

      $this->logger->loggerErrWarn('Provedor Não encontrado.');
      return false;
    }

    return true;
  }

  /**
   * Verifica se a integração tem um link válido
   *
   * @return bool
   */
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

  /**
   * Verifica se o arquivo é um XML válido
   *
   * @param array $fileType
   * @return bool
   */
  public function searchXmlInLink(array $fileType): bool
  {
    if (stripos($fileType[0], 'application/xml') === false && stripos($fileType[0], 'text/xml') === false) {
      $this->logError('Content-Type inválido', [
        'id_integracao' => $this->integration->id,
        'content_type' => $fileType[0],
        'link' => $this->integration->link,
      ]);

      $this->logger->loggerErrWarn(
        'Verifique o Http::get() no local pois não foi encontrado um "aplicativo/xml" nem "text/xml" no cabeçalho, verifique se exista algum dado xml ou se o link é realmente inválido.'
      );

      return false;
    }

    return true;
  }

  /**
   * Verifica se a integração possui um XML válido
   *
   * @return bool
   */
  public function integrationHasValidXml(): bool
  {
    $maxRetries = 3;
    $retryDelay = 10; // Atraso inicial em segundos

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
          $retryDelay *= 2; // Backoff exponencial
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

      $this->logger->loggerErrWarn('Não foi possível abrir o Link no "Http::get()", o link talvez seja inválido.');
      return false;
    }

    return false;
  }

  /**
   * Realiza a requisição HTTP para obter o XML
   *
   * @return \Illuminate\Http\Client\Response|null
   */
  private function requestXml()
  {
    return Http::withUserAgent('ImovelGuide/1.0 (XML Integration Service; +https://imovelguide.com.br)')
      ->timeout(600) // 10 minutos para processamento de XML
      ->get(IntegrationHelper::loadSafeLink($this->integration->link));
  }

  /**
   * Executa a integração completa
   *
   * @param Integracao $integration
   * @param array $options
   * @return bool
   */
  public function doIntegration(Integracao $integration, array $options = []): bool
  {
    try {
      Log::info("🔧 doIntegration() iniciado", [
        'integration_id' => $integration->id,
        'integration_name' => $integration->system ?? 'sem_nome',
        'integration_link' => $integration->link ?? 'sem_link'
      ]);

      if (!$this->canDoIntegration($integration, $options)) {
        Log::warning("❌ canDoIntegration() retornou false", [
          'integration_id' => $integration->id,
          'integration_name' => $integration->system ?? 'sem_nome'
        ]);
        return false;
      }

      Log::info("✅ canDoIntegration() passou - definindo integração");
      $this->setIntegration($integration);

      if (!$this->canParseXml()) {
        Log::warning("❌ canParseXml() retornou false", [
          'integration_id' => $integration->id,
          'integration_name' => $integration->system ?? 'sem_nome'
        ]);
        $this->logError('Falha ao validar XML', [
          'id_integracao' => $integration->id,
          'link_xml' => $integration->link,
        ]);
        return false;
      }

      Log::info("✅ canParseXml() passou - configurando provider");
      $this->getProvider()->setOptions($options);

      Log::info("🚀 Executando steps da integração");
      if (!$this->executeIntegrationSteps()) {
        Log::warning("❌ executeIntegrationSteps() retornou false", [
          'integration_id' => $integration->id,
          'integration_name' => $integration->system ?? 'sem_nome'
        ]);
        return false;
      }

      Log::info("🎉 doIntegration() concluído com sucesso", [
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

  /**
   * Verifica se a integração pode ser executada
   *
   * @param Integracao $integration
   * @param array $options
   * @return bool
   */
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

  /**
   * Verifica se a integração já está em progresso
   *
   * @param Integracao $integration
   * @return bool
   */
  private function integrationIsInProgress(Integracao $integration): bool
  {
    if (
      $integration->status == Integracao::XML_STATUS_IN_UPDATE_BOTH ||
      $integration->status == Integracao::XML_STATUS_IN_DATA_UPDATE ||
      $integration->status == Integracao::XML_STATUS_IN_IMAGE_UPDATE ||
      ($integration->queue &&
        in_array($integration->queue->status, [IntegrationsQueues::STATUS_IN_PROCESS, IntegrationsQueues::STATUS_DONE]))
    ) {
      return true;
    }

    return false;
  }

  /**
   * Executa os passos da integração
   *
   * @return bool
   */
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

  /**
   * Executa o parser do provedor
   *
   * @return bool
   */
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

  /**
   * Executa a preparação de dados do provedor
   *
   * @return bool
   */
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

  /**
   * Executa a inserção de dados do provedor
   *
   * @return bool
   */
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

  /**
   * Remove registros relacionados a um anúncio
   *
   * @param \App\Anuncio $anuncio
   * @return void
   */
  public function deleteRelatedRecords($anuncio): void
  {
    // Deletar registros relacionados primeiro
    DB::table('anuncio_images')->where('anuncio_id', $anuncio->id)->delete();
    DB::table('anuncio_beneficio')->where('anuncio_id', $anuncio->id)->delete();
    DB::table('anuncio_enderecos')->where('anuncio_id', $anuncio->id)->delete();
    DB::table('lista_corretores_da_construtora')->where('anuncio_id', $anuncio->id)->delete();
    // Agora pode deletar o anúncio
    $anuncio->delete();
  }

  /**
   * Registra um erro no canal de log do Discord
   *
   * @param string $message
   * @param array $context
   * @return void
   */
  private function logError(string $message, array $context = []): void
  {
    $this->discordLogger->error($message, $context);
  }
}
