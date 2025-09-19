<?php

namespace App\Integracao\Infrastructure\Parsers\Models;

use Mail;
use Image;
use Carbon\Carbon;
use DiDom\Document;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Integracao\Application\Services\XMLIntegrationLoggerService;
use App\Services\LevelsPlansPermissionService;
use App\User;
use App\Anuncio;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Domain\Entities\IntegrationsQueues;

abstract class XMLBaseParser
{
  private const DEFAULT_TYPE_IMOVEL = 19;
  private $logger = null;
  protected $integration = null;
  private $parsed = false;
  private $xml = null;
  protected $data = [];
  protected $toLog = [];
  protected $adsWNCep = [];
  protected $featuresNFound = [];
  protected $adsTypesNFound = [];
  protected $adsStatusNFound = [];
  protected $adsUFNFound = [];
  protected $adsIMGNFound = [];
  protected $adsNegotiationNFound = [];
  protected $adsGuaranteeNFound = [];
  protected $adsYtNFound = [];
  protected $imoveisCount = 0;
  protected $quantityMade = 0;
  protected $imovelCode = 0;
  protected $isManual = false;
  protected $isUpdate = false;
  protected $updateType = null;
  protected $LPPService;
  protected $userData = [];

  abstract protected function parserXml(): void;
  abstract protected function prepareXmlData(): void;
  abstract protected function insertXmlData(): void;
  abstract protected function parserImovelType(string $imovelType): array;
  abstract protected function parserOfferType(string $offerType, $precoLocacao, $precoTemporada): int;
  abstract protected function parserDescription(string $description): string;
  abstract protected function parserGuarantee(string $guarantee): int;
  abstract protected function parserStatus(string $status): int;
  abstract protected function parserAreaUtil(string $area): int;
  abstract protected function parserAreaConstruida(string $area): int;
  abstract protected function parserAreaTotal(string $area): int;
  abstract protected function parserAreaTerreno(string $area): int;
  abstract protected function parserFeatures(array $features): array;
  abstract protected function parserUF(string $uf): string;
  abstract protected function parserImageUrl(array $images): array;
  abstract protected function parserCEP(string $cep): string;
  abstract protected function parserImovelTitleAndSlug(array $imovel): array;
  abstract protected function parserNegotiation(array $imovel): int;
  abstract protected function parserYoutubeVideo(string $url): Mixed;
  abstract protected function parserValorM2(int $precoVenda, int $areaUtil): Mixed;

  protected function __construct(Document $xml, Integracao $integration)
  {
    $this->xml = $xml;
    $this->integration = $integration;
    $this->logger = new XMLIntegrationLoggerService($this->integration);
    $this->updateType = Integracao::XML_STATUS_IN_UPDATE_BOTH;
    $this->LPPService = new LevelsPlansPermissionService();

    // Otimização: Cache de dados do usuário
    $this->cacheUserData();
  }

  private function cacheUserData(): void
  {
    if ($this->integration && $this->integration->user) {
      $user = $this->integration->user;

      // Cache de dados que serão usados frequentemente
      $this->userData = [
        'id' => $user->id,
        'level' => $user->level ?? 0,
        'inative' => $user->inative,
        'integration_priority' => $user->integration_priority ?? 0,
        'asaas_sub' => $user->asaasSub ? [
            'status' => $user->asaasSub->status,
            'data_cancelamento' => $user->asaasSub->data_cancelamento
        ] : null
      ];
    }
  }

  protected function isParsed(): bool
  {
    return $this->parsed;
  }

  protected function setParsed(bool $bool)
  {
    $this->parsed = $bool;
  }

  protected function getXml(): Document
  {
    return $this->xml;
  }

  protected function getDefaultTypeId()
  {
    return self::DEFAULT_TYPE_IMOVEL;
  }

  // Método comum: seta opções básicas.
  public function setOptions(array $options): void
  {
    foreach ($options as $key => $value) {
      switch ($key) {
        case 'isManual':
          $this->isManual = $value;
          break;
        case 'isUpdate':
          $this->isUpdate = $value;
          break;
        case 'updateType':
          $this->updateType = $value;
          break;

        default:
          break;
      }
    }

    if ($this->isUpdate) {
      $this->integration->status = $this->updateType;
      $this->integration->save();
    }
  }

  // Método comum: chama o parserXml de toda classe que herda a classe base.
  public function parser(): void
  {
    try {
      $this->parserXml();
    } catch (\Exception $e) {
      \Log::error('Erro no parser XML', [
        'integration_id' => $this->integration->id,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      throw new \Exception("XML parsing failed: " . $e->getMessage(), 0, $e);
    }
  }

  // Método comum: chama o prepareXmlData de toda classe que herda a classe base.
  public function prepareData(): void
  {
    try {
      $this->prepareXmlData();
    } catch (\Exception $e) {
      \Log::error('Erro ao preparar dados XML', [
        'integration_id' => $this->integration->id,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      throw new \Exception("XML data preparation failed: " . $e->getMessage(), 0, $e);
    }
  }

  // Método comum: chama o insertXmlData de toda classe que herda a classe base.
  public function insertData(): void
  {
    try {
      $this->insertXmlData();
    } catch (\Exception $e) {
      \Log::error('Erro ao inserir dados XML', [
        'integration_id' => $this->integration->id,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      throw new \Exception("XML data insertion failed: " . $e->getMessage(), 0, $e);
    }
  }

  // Método comum: Pega a quantidade de imóveis totais no xml.
  public function getImoveisCount(): int
  {
    return $this->imoveisCount;
  }

  // Método comum: Pega quantidade de imóveis feitos.
  public function getImoveisMade(): int
  {
    return $this->quantityMade;
  }

  // Método comum: Compara os imóveis pra ver se precisa de update.
  protected function isDifferentImovel($existingImovel, $data): bool
  {
    return $existingImovel->type_id != $data['type_id'] ||
      $existingImovel->new_immobile != $data['new_immobile'] ||
      $existingImovel->negotiation_id != $data['negotiation_id'] ||
      $existingImovel->condominio_mes != $data['condominio_mes'] ||
      $existingImovel->valor != $data['valor'] ||
      $existingImovel->valor_aluguel != $data['valor_aluguel'] ||
      $existingImovel->valor_temporada != $data['valor_temporada'] ||
      $existingImovel->rental_guarantee != $data['rental_guarantee'] ||
      $existingImovel->area_total != $data['area_total'] ||
      $existingImovel->area_util != $data['area_util'] ||
      $existingImovel->area_terreno != $data['area_terreno'] ||
      $existingImovel->area_construida != $data['area_construida'] ||
      $existingImovel->bedrooms != $data['bedrooms'] ||
      $existingImovel->suites != $data['suites'] ||
      $existingImovel->bathrooms != $data['bathrooms'] ||
      $existingImovel->parking != $data['parking'] ||
      $existingImovel->description != $data['description'] ||
      $existingImovel->title != $data['title'] ||
      $existingImovel->iptu != $data['iptu'] ||
      $existingImovel->spotlight != $data['spotlight'] ||
      $existingImovel->subtitle != $data['subtitle'] ||
      $existingImovel->exchange != $data['exchange'] ||
      $existingImovel->youtube != $data['youtube'] ||
      $existingImovel->xml != 1;
  }

  // Método comum: Compara os imóveis pra ver se precisa de update.
  protected function isDifferentCondominium($existingCondominium, $condominiumData): bool
  {
    return $existingCondominium->condominiun_id != $condominiumData['condominiun_id'] ||
      $existingCondominium->builder_id != $condominiumData['builder_id'] ||
      $existingCondominium->number_of_floors != $condominiumData['number_of_floors'] ||
      $existingCondominium->units_per_floor != $condominiumData['units_per_floor'] ||
      $existingCondominium->number_of_towers != $condominiumData['number_of_towers'] ||
      $existingCondominium->construction_year != $condominiumData['construction_year'] ||
      $existingCondominium->terrain_size != $condominiumData['terrain_size'];
  }

  // Método comum: Compara os imóveis pra ver se precisa de update.
  protected function isDifferentLocation($condLocation, $checkLocation): bool
  {
    return $condLocation->mostrar_endereco != $checkLocation['mostrar_endereco'] ||
      $condLocation->cep != $checkLocation['cep'] ||
      $condLocation->cidade != $checkLocation['cidade'] ||
      $condLocation->slug_cidade != $checkLocation['slug_cidade'] ||
      $condLocation->uf != $checkLocation['uf'] ||
      $condLocation->bairro != $checkLocation['bairro'] ||
      $condLocation->slug_bairro != $checkLocation['slug_bairro'] ||
      $condLocation->logradouro != $checkLocation['logradouro'] ||
      $condLocation->numero != $checkLocation['numero'] ||
      $condLocation->bairro_comercial != $checkLocation['bairro_comercial'] ||
      $condLocation->complement != $checkLocation['complement'] ||
      $condLocation->latitude != $checkLocation['latitude'] ||
      $condLocation->longitude != $checkLocation['longitude'] ||
      $condLocation->valid_location != $checkLocation['valid_location'];
  }

  protected function getMaxImgSize(): int
  {
    return self::MAX_IMAGE_SIZE / (1024 * 1024);
  }

  protected function getImageInfo($url)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $result = curl_exec($ch);

    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $content_length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $content_redirect = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    curl_close($ch);
    if ($result !== false) {
      return ['extension' => $content_type, 'size' => $content_length, $content_redirect];
    } else {
      return false;
    }
  }

  protected function reduceImage($s3Path, $imageFileName)
  {
    try {
      $cleanImageName = str_replace('integration/', '', $imageFileName);

      // Verificar se a imagem existe no S3
      if (!Storage::disk('do_spaces')->exists($s3Path)) {
        return;
      }

      // Baixar imagem do S3 para processamento
      $imageData = Storage::disk('do_spaces')->get($s3Path);
      $tempPath = tempnam(sys_get_temp_dir(), 'integration_img_');
      file_put_contents($tempPath, $imageData);

      // Processar imagem original (LARGE)
      $img = Image::make($tempPath)
        ->orientate()
        ->resize(768, 432, function ($constraint) {
          $constraint->aspectRatio();
        });

      // Salvar versão LARGE de volta no S3
      $largeData = $img->encode('webp', 85)->getEncoded();
      Storage::disk('do_spaces')->put($s3Path, $largeData, 'public');

      // Criar versão SMALL
      $smallImg = Image::make($tempPath)
        ->orientate()
        ->resize(280, 250, function ($constraint) {
          $constraint->aspectRatio();
        });

      $smallData = $smallImg->encode('webp', 85)->getEncoded();
      Storage::disk('do_spaces')->put("images/integration/properties/small/{$cleanImageName}.webp", $smallData, 'public');

      // Criar versão MEDIUM
      $mediumImg = Image::make($tempPath)
        ->orientate()
        ->resize(360, 280, function ($constraint) {
          $constraint->aspectRatio();
        });

      $mediumData = $mediumImg->encode('webp', 85)->getEncoded();
      Storage::disk('do_spaces')->put("images/integration/properties/medium/{$cleanImageName}.webp", $mediumData, 'public');

      // Limpar arquivo temporário
      unlink($tempPath);

    } catch (\Exception $e) {
      \Log::error('Erro ao redimensionar imagem de integração: ' . $e->getMessage(), [
        's3Path' => $s3Path,
        'imageFileName' => $imageFileName,
      ]);
    }
  }

  protected function deleteIntegrationImage($imageFileName)
  {
    try {
      $curImageName = str_replace('integration/', '', $imageFileName);

      // Remover imagem original do S3
      $originalPath = "images/integration/{$curImageName}";
      if (Storage::disk('do_spaces')->exists($originalPath)) {
        Storage::disk('do_spaces')->delete($originalPath);
      }

      // Remover versão medium do S3 (tenta .webp primeiro, depois sem extensão)
      $mediumPathWebp = "images/integration/properties/medium/{$curImageName}.webp";
      $mediumPathOld = "images/integration/properties/medium/{$curImageName}";

      if (Storage::disk('do_spaces')->exists($mediumPathWebp)) {
        Storage::disk('do_spaces')->delete($mediumPathWebp);
      } elseif (Storage::disk('do_spaces')->exists($mediumPathOld)) {
        Storage::disk('do_spaces')->delete($mediumPathOld);
      }

      // Remover versão small do S3 (tenta .webp primeiro, depois sem extensão)
      $smallPathWebp = "images/integration/properties/small/{$curImageName}.webp";
      $smallPathOld = "images/integration/properties/small/{$curImageName}";

      if (Storage::disk('do_spaces')->exists($smallPathWebp)) {
        Storage::disk('do_spaces')->delete($smallPathWebp);
      } elseif (Storage::disk('do_spaces')->exists($smallPathOld)) {
        Storage::disk('do_spaces')->delete($smallPathOld);
      }
    } catch (\Exception $e) {
      \Log::error('Erro ao excluir imagem de integração: ' . $e->getMessage(), [
        'imageFileName' => $imageFileName,
      ]);
    }
  }

  protected function canUpdateIntegrationStatus(): bool
  {
    return $this->quantityMade > 0;
  }

  protected function logDone(): void
  {
    $logProblems = '';
    if (count($this->adsWNCep)) {
      $this->toLog[] = 'Não foram encontrados os CEPS dos seguintes anúncios: ' . implode(', ', $this->adsWNCep) . '.';
    }

    if (count($this->featuresNFound)) {
      $this->toLog[] = 'Não foram encontrados as seguintes Features: ' . implode(', ', $this->featuresNFound) . '.';
    }

    if (count($this->adsTypesNFound)) {
      $this->toLog[] =
        'Os seguintes tipos de imóveis não foram encontrados: ' . implode(', ', $this->adsTypesNFound) . '.';
    }

    if (count($this->adsStatusNFound)) {
      $this->toLog[] = 'Os seguintes status não foram encontrados: ' . implode(', ', $this->adsStatusNFound) . '.';
    }

    if (count($this->adsUFNFound)) {
      $this->toLog[] = 'Os seguintes UF não foram encontrados: ' . implode(', ', $this->adsUFNFound) . '.';
    }

    if (count($this->adsIMGNFound)) {
      $this->toLog[] =
        'Os seguintes anúncios tinham imagens com urls invalidas: ' . implode(', ', $this->adsIMGNFound) . '.';
    }

    if (count($this->adsNegotiationNFound)) {
      $this->toLog[] =
        'Não foram encontrados o negotiationID dos seguintes imóveis: ' .
        implode(', ', $this->adsNegotiationNFound) .
        '.';
    }

    if (count($this->adsGuaranteeNFound)) {
      $this->toLog[] =
        'As seguintes garantias de alugueis não foram encontradas: ' . implode(', ', $this->adsGuaranteeNFound) . '.';
    }

    if (count($this->adsYtNFound)) {
      $this->toLog[] =
        'O link do Youtube dos seguintes imóveis não foram processados corretamente: ' .
        implode(', ', $this->adsYtNFound) .
        '.';
    }

    if (count($this->toLog)) {
      $logProblems = implode("\n", $this->toLog);
      $this->logger->loggerDone($this->imoveisCount, $this->quantityMade, $logProblems);
      return;
    }

    $this->logger->loggerDone($this->imoveisCount, $this->quantityMade);
  }

  public function sendEmail($userId)
  {
    $user = User::select('name', 'email')->where('id', $userId)->first();
    $user_email = $user->email;
    Mail::send(
      'emails/emailIntegracao',
      [
        'userName' => str_slug($user->name),
        'userId' => $userId,
      ],
      function ($m) use ($user_email) {
        $m->from('naoresponda@imovelguide.com.br', 'Imóvel Guide');
        $m->to($user_email, 'Imóvel Guide');
        $m->bcc('sentimovelguide@gmail.com', 'Imóvel Guide');
        $m->subject('Envio xml | Processamento xml');
      }
    );
  }

  public function startIntegration()
  {
    if ($this->integration->queue) {
      $this->integration->queue->status = IntegrationsQueues::STATUS_IN_PROCESS;
      $this->integration->queue->started_at = now(); // ✅ CORREÇÃO: Usar now() do Laravel
      $this->integration->queue->save();
    }
  }

  public function endIntegration()
  {
    if ($this->integration->queue) {
      // ✅ CORREÇÃO: Só atualizar se não estiver já finalizado
      if ($this->integration->queue->status != IntegrationsQueues::STATUS_DONE) {
        $this->integration->queue->status = IntegrationsQueues::STATUS_DONE;
        $this->integration->queue->ended_at = now(); // ✅ CORREÇÃO: Usar now() do Laravel
        $this->integration->queue->save();
      }
    }
  }

  public function endIntegrationWithErrorStatus()
  {
    if ($this->integration->queue) {
      $this->integration->queue->status = IntegrationsQueues::STATUS_ERROR;
      $this->integration->queue->ended_at = now(); // ✅ CORREÇÃO: Usar now() do Laravel
      $this->integration->queue->save();
    }

    $this->integration->status = Integracao::XML_STATUS_IN_ANALYSIS;
    $this->integration->save();
  }

  public function removeOldData($imovelData)
  {
    // Otimização: Usar dados em cache do usuário
    $userId = $this->userData['id'] ?? $this->integration->user->id;

    // Otimização: Buscar apenas os códigos dos imóveis atuais para comparação
    $currentCodes = collect($imovelData)->pluck('CodigoImovel')->filter()->toArray();

    // Otimização: Query mais eficiente com eager loading
    $imoveisXML = Anuncio::with(['gallery', 'anuncioBeneficio', 'endereco'])
      ->where('user_id', $userId)
      ->where('xml', 1)
      ->whereNotIn('codigo', $currentCodes) // Filtrar direto na query
      ->get();

    $deletedCount = 0;
    $imageCount = 0;

    // Otimização: Processar em lotes para melhor performance
    $imoveisXML->chunk(50)->each(function ($chunk) use (&$deletedCount, &$imageCount) {
      $anuncioIds = $chunk->pluck('id')->toArray();

      // Coletar todas as imagens para deletar
      $imagesToDelete = [];
      foreach ($chunk as $xml) {
        foreach ($xml->gallery as $image) {
          $imagesToDelete[] = $image->name;
        }
      }

      // Deletar imagens em lote
      foreach ($imagesToDelete as $imageName) {
        $this->deleteIntegrationImage($imageName);
        $imageCount++;
      }

      // Deletar registros relacionados em lote
      DB::table('lista_corretores_da_construtora')->whereIn('anuncio_id', $anuncioIds)->delete();
      DB::table('anuncio_images')->whereIn('anuncio_id', $anuncioIds)->delete();
      DB::table('anuncio_beneficio')->whereIn('anuncio_id', $anuncioIds)->delete();
      DB::table('anuncio_enderecos')->whereIn('anuncio_id', $anuncioIds)->delete();

      // Deletar anúncios em lote
      Anuncio::whereIn('id', $anuncioIds)->delete();
      $deletedCount += count($anuncioIds);
    });

    // Otimização: Atualizações em lote para melhor performance
    DB::table('anuncios')
      ->where('user_id', $userId)
      ->where('xml', 0)
      ->update([
        'ig_highlight' => 0,
        'status' => 'desativado',
      ]);

    // Unhighlight all xml ads.
    DB::table('anuncios')
      ->where('user_id', $userId)
      ->where('xml', 1)
      ->update([
        'ig_highlight' => 0,
      ]);

    // Otimização: Processar highlights de forma mais eficiente
    $adsHighlightLimits = $this->LPPService->getAdsLimits($this->integration->user);
    $userAdsLimit = $adsHighlightLimits['limit'] + $adsHighlightLimits['bonus'];

    $highlightedAds = collect($imovelData)
      ->filter(function ($value) {
        return !empty($value['Highlighted']) && $value['Highlighted'];
      })
      ->take($userAdsLimit)
      ->pluck('CodigoImovel')
      ->filter()
      ->toArray();

    // Otimização: Update em lote para highlights
    if (!empty($highlightedAds)) {
      DB::table('anuncios')
        ->where('user_id', $userId)
        ->where('xml', 1)
        ->whereIn('codigo', $highlightedAds)
        ->update(['ig_highlight' => 1]);
    }

    // Log de performance
    Log::info("Old data removal completed", [
      'integration_id' => $this->integration->id,
      'deleted_ads' => $deletedCount,
      'deleted_images' => $imageCount,
      'highlighted_ads' => count($highlightedAds)
    ]);

    return true;
  }
}