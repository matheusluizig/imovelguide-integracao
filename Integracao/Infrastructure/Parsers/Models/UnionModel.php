<?php

namespace App\Integracao\Infrastructure\Parsers\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use DiDom\Document;
use Carbon\Carbon;
use Storage;
use Image;
use App\User;
use App\Imovel;
use App\Bairro;
use App\Anuncio;
use App\ImovelType;
use App\Integracao\Domain\Entities\Integracao;
use App\AnuncioImages;
use App\ImovelFeatures;
use App\BrazilianStates;
use App\AnuncioEndereco;
use App\CondominiumData;
use App\AnuncioBeneficio;
use App\Services\AnuncioService;
use App\Integracao\Infrastructure\Parsers\Models\XMLBaseParser;
use App\Services\InviteService;

class UnionModel extends XMLBaseParser {
    public function __construct(Document $xml, Integracao $integration) {
        parent::__construct($xml, $integration);
        $this->startIntegration();
    }



    protected function parserXml() : Void {
        $imoveis = $this->getXml()->find('Imovel');
        $this->imoveisCount = count($imoveis);
        \Log::channel('integration')->info('🧩 PARSER: Imóveis encontrados no XML (Union)', [
            'integration_id' => $this->integration->id ?? null,
            'provider' => 'Union',
            'imoveis_count' => $this->imoveisCount
        ]);

        foreach ($imoveis as $index => $imovel) {

            $data = [];
            $codigoImovel = $imovel->find('Codigoimovel');
            $data['CodigoImovel'] = count($codigoImovel) > 0 ? $codigoImovel[0]->text() : '';
            $data['Subtitle'] = NULL;
            $subTitle = $imovel->find('Titulo');
            if (count($subTitle)) {
                $data['Subtitle'] = $subTitle[0]->text();
            }

            $data['Descricao'] = '';
            if ($imovel->has('Anuncioparainternet')) {
                $anuncioParaInternet = $imovel->find('Anuncioparainternet');
                $data['Descricao'] = count($anuncioParaInternet) > 0 ? $anuncioParaInternet[0]->text() : '';
            }

            $season = $imovel->find('Temporada');
            $rent = $imovel->find('Locacao');
            $sale = $imovel->find('Venda');

            $data['TipoOferta'] = $this->getOfferType($season, $rent, $sale);

            $data['PrecoVenda'] = 0;
            if ($imovel->has('Valorvenda')) {
                $valorVenda = $imovel->find('Valorvenda');
                $data['PrecoVenda'] = count($valorVenda) > 0 ? $valorVenda[0]->text() : 0;
                if ($data['PrecoVenda'] == '') {
                    $data['PrecoVenda'] = 0;
                }
            }
            if (empty($data['PrecoVenda'])) {
                $data['PrecoVenda'] = 0;
            }

            $data['PrecoLocacao'] = 0;
            if ($imovel->has('Valorlocacao')) {
                $valorLocacao = $imovel->find('Valorlocacao');
                $data['PrecoLocacao'] = count($valorLocacao) > 0 ? $valorLocacao[0]->text() : 0;
                if ($data['PrecoLocacao'] == '') {
                    $data['PrecoLocacao'] = 0;
                }
            }

            $data['PrecoTemporada'] = NULL;
            if ($imovel->has('Valortemporada')) {
                $valorTemporada = $imovel->find('Valortemporada');
                $data['PrecoTemporada'] = count($valorTemporada) > 0 ? $valorTemporada[0]->text() : NULL;
                if ($data['PrecoTemporada'] == '') {
                    $data['PrecoTemporada'] = NULL;
                }
            }

            $data['Spotlight'] = 0;
            $data['Highlighted'] = NULL;
            if ($imovel->has('Destaque')) {
                $destaque = $imovel->find('Destaque');
                $data['Highlighted'] = count($destaque) > 0 ? intval($destaque[0]->text()) : NULL;
            }

            $data['GarantiaAluguel'] = NULL;

            $data['ValorIPTU'] = NULL;
            if ($imovel->has('Valoriptu')) {
                $valorIptu = $imovel->find('Valoriptu');
                $data['ValorIPTU'] = count($valorIptu) > 0 ? $valorIptu[0]->text() : NULL;
            }

            $data['PrecoCondominio'] = NULL;
            if ($imovel->has('Valorcondominio')) {
                $valorCondominio = $imovel->find('Valorcondominio');
                $data['PrecoCondominio'] = count($valorCondominio) > 0 ? $valorCondominio[0]->text() : NULL;
                if ($data['PrecoCondominio'] == '') {
                    $data['PrecoCondominio'] = NULL;
                }
            }

            $data['Permuta'] = 0;
            if ($imovel->has('Permuta')) {
                $permuta = $imovel->find('Permuta');
                $data['Permuta'] = count($permuta) > 0 ? intval($permuta[0]->text()) : 0;
            }

            $data['Andares'] = NULL;
            if ($imovel->has('Andares')) {
                $andares = $imovel->find('Andares');
                $data['Andares'] = count($andares) > 0 ? $andares[0]->text() : NULL;
            }

            $data['UnidadesAndar'] = NULL;

            $data['Torres'] = NULL;

            $data['Construtora'] = 0;
            if ($imovel->has('Construtora')) {
                $construtora = $imovel->find('Construtora');
                $data['Construtora'] = count($construtora) > 0 ? $construtora[0]->text() : 0;
            }

            $data['MostrarEndereco'] = 2;

            $data['AreaTotal'] = NULL;
            if ($imovel->has('Areatotalsemdeciamal')) {
                $areaTotalSemDecimal = $imovel->find('Areatotalsemdeciamal');
                $data['AreaTotal'] = count($areaTotalSemDecimal) > 0 ? $areaTotalSemDecimal[0]->text() : NULL;
            }
            if (!$data['AreaTotal']) {
                if ($imovel->has('Areatotal')) {
                    $areaTotal = $imovel->find('Areatotal');
                    $data['AreaTotal'] = count($areaTotal) > 0 ? $areaTotal[0]->text() : NULL;
                }
            }

            $tipo = $imovel->find('Tipo');
            $data['TipoImovel'] = count($tipo) > 0 ? $tipo[0]->text() : '';
            $data['NomeImovel'] = "";
            $data['Novo'] = NULL;

            $data['AreaUtil'] = 0;
            if ($imovel->has('Areautilsemdeciamal')) {
                $areaUtilSemDecimal = $imovel->find('Areautilsemdeciamal');
                $data['AreaUtil'] = count($areaUtilSemDecimal) > 0 ? $areaUtilSemDecimal[0]->text() : 0;
            }
            if (!$data['AreaUtil']) {
                if ($imovel->has('Areautil')) {
                    $areaUtil = $imovel->find('Areautil');
                    $data['AreaUtil'] = count($areaUtil) > 0 ? $areaUtil[0]->text() : 0;
                }
            }
            if (empty($data['AreaUtil'])) {
                $data['AreaUtil'] = 0;
            }

            $data['AreaTerreno'] = 0;
            if ($imovel->has('Areaterrenosemdeciamal')) {
                $areaTerrenoSemDecimal = $imovel->find('Areaterrenosemdeciamal');
                $data['AreaTerreno'] = count($areaTerrenoSemDecimal) > 0 ? $areaTerrenoSemDecimal[0]->text() : 0;
            }
            if (!$data['AreaTerreno']) {
                if ($imovel->has('Areaterreno')) {
                    $areaTerreno = $imovel->find('Areaterreno');
                    $data['AreaTerreno'] = count($areaTerreno) > 0 ? $areaTerreno[0]->text() : 0;
                }
            }
            if ($data['AreaTerreno'] == '') {
                $data['AreaTerreno'] = 0;
            }

            $data['AreaConstruida'] = NULL;
            if ($imovel->has('Areacosntruidasemdeciamal')) {
                $data['AreaConstruida'] = $imovel->find('Areacosntruidasemdeciamal')[0]->text();
            }
            if (!$data['AreaConstruida']) {
                if ($imovel->has('Areacosntruida')) {
                    $data['AreaConstruida'] = $imovel->find('Areacosntruida')[0]->text();
                }
            }
            if ($data['AreaConstruida'] == '') {
                $data['AreaConstruida'] = 0;
            }

            $data['AnoConstrucao'] = 0;
            if ($imovel->has('AnoConstrucao')) {
                $data['AnoConstrucao'] = $imovel->find('AnoConstrucao')[0]->text();
            }

            $data['QtdDormitorios'] = 0;
            if ($imovel->has('Dormitorios')) {
                $data['QtdDormitorios'] = $imovel->find('Dormitorios')[0]->text();
            }

            $data['QtdSuites'] = NULL;
            if ($imovel->has('Suite')) {
                $data['QtdSuites'] = $imovel->find('Suite')[0]->text();
            }

            $data['QtdBanheiros'] = 0;
            if ($imovel->has('Banheiro2')) {
                $data['QtdBanheiros'] = $imovel->find('Banheiro2')[0]->text();
            }

            $data['QtdVagas'] = 0;
            if ($imovel->has('Garagem')) {
                $data['QtdVagas'] = $imovel->find('Garagem')[0]->text();
            }

            $data['Features'] = $this->getFeatures($imovel);

            $data['UF'] = $imovel->find('UnidadeFederativa')[0]->text();
            $data['Cidade'] = '';
            if ($imovel->has('Cidade')) {
                $data['Cidade'] = $imovel->find('Cidade')[0]->text();
            }

            $data['Bairro'] = $imovel->find('Bairro')[0]->text();
            $data['BairroComercial'] = NULL;
            $businessDistrict = $imovel->find('Bairrocomercial');
            if (count($businessDistrict)) {
                $data['BairroComercial'] = $businessDistrict[0]->text();
            }

            $data['CEP'] = 0;

            $cep = $imovel->find('CEP');
            if (count($cep)) {
                $data['CEP'] = $cep[0]->text();
            }

            $data['Endereco'] = '';
            if ($imovel->has('Endereco')) {
                $data['Endereco'] = $imovel->find('Endereco')[0]->text();
            }

            $data['Numero'] = NULL;
            if ($imovel->has('Numero')) {
                $data['Numero'] = $imovel->find('Numero')[0]->text();
            }

            $data['Complemento'] = NULL;
            if ($imovel->has('Complemento')) {
                $data['Complemento'] = $imovel->find('Complemento')[0]->text();
            }

            $data['Latitude'] = NULL;
            if ($imovel->has('Latitude')) {
                $data['Latitude'] = $imovel->find('Latitude')[0]->text();
            }

            $data['Longitude'] = NULL;
            if ($imovel->has('Longitude')) {
                $data['Longitude'] = $imovel->find('Longitude')[0]->text();
            }

            $data['Video'] = NULL;
            $videos = $imovel->find('Videos');
            if (count($videos)) {
                foreach ($videos[0]->children() as $video) {
                    if ($video->has('LinkVideo')) {
                        $data['Video'] = $video->find('LinkVideo')[0]->text();
                        break;
                    }
                }
            }

            $data['images'] = [];
                $images = $imovel->find('Fotos');
                $imagesCounter = 0;
                if (count($images)) {
                    foreach ($images[0]->children() as $media) {
                        $image = $media->find('URL');
                        if (count($image)) {
                            $data['images'][] = $image[0]->text();
                            ++$imagesCounter;
                        }

                        if ($imagesCounter == 20) {
                            break;
                        }
                    }
                }

            $this->data[$index] = $data;
        }
    }



    protected function prepareXmlData() : Void {
        foreach ($this->data as $key => $imovel) {

            $imovel['CodigoImovel'] = trim($imovel['CodigoImovel']);
            $this->imovelCode = $imovel['CodigoImovel'];

            $imovelTypeAndName = $this->parserImovelType($imovel['TipoImovel']);
            $imovel['TipoImovel'] = $imovelTypeAndName['TipoImovel'];
            $imovel['NomeImovel'] = $imovelTypeAndName['NomeImovel'];

            $imovel['Descricao'] = $this->parserDescription($imovel['Descricao']);

            if ($imovel['Subtitle']) {
                $imovel['Subtitle'] = $this->parserDescription($imovel['Subtitle']);
            }

            if ($imovel['PrecoVenda']) {
                $imovel['PrecoVenda'] = convertToNumber($imovel['PrecoVenda']);
            }

            if ($imovel['PrecoLocacao']) {
                $imovel['PrecoLocacao'] = convertToNumber($imovel['PrecoLocacao']);
            }

            if ($imovel['PrecoTemporada']) {
                $imovel['PrecoTemporada'] = convertToNumber($imovel['PrecoTemporada']);
            }

            if ($imovel['GarantiaAluguel']) {
                $imovel['GarantiaAluguel'] = $this->parserGuarantee($imovel['GarantiaAluguel']);
            }

            if ($imovel['Novo']) {
                $imovel['Novo'] = $this->parserStatus($imovel['Novo']);
            }

            if ($imovel['AreaUtil']) {
                $imovel['AreaUtil'] = $this->parserAreaUtil($imovel['AreaUtil']);
            }

            if ($imovel['AreaConstruida']) {
                $imovel['AreaConstruida'] = $this->parserAreaConstruida($imovel['AreaConstruida']);
            }

            if ($imovel['AreaTotal']) {
                $imovel['AreaTotal'] = $this->parserAreaTotal($imovel['AreaTotal']);
            }

            if ($imovel['AreaTerreno']) {
                $imovel['AreaTerreno'] = $this->parserAreaTerreno($imovel['AreaTerreno']);
            }

            if (count($imovel['Features'])) {
                $imovel['Features'] = $this->parserFeatures($imovel['Features']);
            }

            if ($imovel['UF'] && mb_strlen($imovel['UF']) > 2) {
                $imovel['UF'] = $this->parserUF($imovel['UF']);
            }

            $imovel['Cidade'] = unicode_conversor($imovel['Cidade']);

            $imovel['Bairro'] = unicode_conversor($imovel['Bairro']);

            $imovel['CEP'] = $this->parserCEP($imovel['CEP']);

            if ($imovel['Endereco']) {
                $imovel['Endereco'] = str_replace(',', '', $imovel['Endereco']);
            }

            if (count($imovel['images'])) {
                $imovel['images'] = $this->parserImageUrl($imovel['images']);
            }

            $imovelTitleAndSlug = $this->parserImovelTitleAndSlug($imovel);
            $imovel['ImovelTitle'] = $imovelTitleAndSlug['ImovelTitle'];
            $imovel['ImovelSlug'] = $imovelTitleAndSlug['ImovelSlug'];

            if ($imovel['Video']) {
                $imovel['Video'] = $this->parserYoutubeVideo($imovel['Video']);
            }

            $imovel['valor_m2'] = $this->parserValorM2($imovel['PrecoVenda'], $imovel['AreaUtil']);

            $imovel['NegotiationId'] = $this->parserNegotiation($imovel);

            $imovel['CidadeSlug'] = Str::slug($imovel['Cidade']);
            $imovel['BairroSlug'] = Str::slug($imovel['Bairro']);

            $this->data[$key] = $imovel;
        }

        $collection = collect($this->data);
        $duplicatesEntry = $collection->duplicates('CodigoImovel');
        if ($duplicatesEntry instanceof \Illuminate\Support\Collection) {
            foreach ($duplicatesEntry->all() as $key => $value) {
                $collection->forget($key);
            }
            if ($duplicatesEntry->count()) {
                $duplicatesIds = implode(" - ", $duplicatesEntry->toArray());
                $this->toLog[] = "Os seguintes imóveis não foram inseridos por duplicidade(Baseado no código do imóvel): {$duplicatesIds}.";
            }
        }
        $this->data = $collection;
    }

    private function getOfferType(Array $season, Array $rent, Array $sale) : Int {
        $isSeason = 0;
        $isRent = 0;
        $isSale = 0;

        if (count($season)) {
            $isSeason = intval($season[0]->text());
        }

        if (count($rent)) {
            $isRent = intval($rent[0]->text());
        }

        if (count($sale)) {
            $isSale = intval($sale[0]->text());
        }

        if (!$isSale && !$isRent && !$isSeason) {
            return 5;
        } elseif (!$isSale) {
            if (!$isRent) {
                return 3;
            } elseif (!$isSeason) {
                return 6;
            } else {
                return 1;
            }
        } elseif (!$isRent) {
            if (!$isSeason) {
                return 7;
            } else {
                return 2;
            }
        } elseif (!$isSeason) {
            return 4;
        } else {
            return 1;
        }
    }

    private function getFeatures($imovel) : Array {
        $features = [];

        $features['Piscina'] = $imovel->find('Piscina');
        $features['Ar Condicionado'] = $imovel->find('Arcondicionado');

        $features['Sacada'] = $imovel->find('Sacada');
        $features['Depósito'] = $imovel->find('Deposito');

        $features['Churrasqueira'] = $imovel->find('Churrasqueira');
        $features['Elevador'] = $imovel->find('Elevador');
        $features['Academia'] = $imovel->find('EmpreAcademia');
        $features['Salão de Festa'] = $imovel->find('Salafesta');
        $features['Playground'] = $imovel->find('Playground');

        $features['Quadra Poliesportiva'] = $imovel->find('Quadrapoliesportiva');
        $features['Banheiro para Empregada'] = $imovel->find('Banheiroempregada');
        $features['Dormitório para Empregada'] = $imovel->find('Dormitoriosempregada');
        $features['Varanda Gourmet'] = $imovel->find('Varandagourmet');
        $features['Varanda'] = $imovel->find('Varanda');

        $features['Armário de Cozinha Planejado'] = $imovel->find('Armariocozinha');
        $features['Armário do Quarto Planejado'] = $imovel->find('Armariodormitorio');

        $features['Piscina Infantil Aberta'] = $imovel->find('Piscinainfantil');

        $features['Quadra de Squash'] = $imovel->find('Quadrasquash');
        $features['Campo de Futebol'] = $imovel->find('Campofutebol');

        $features['Salão de Jogos'] = $imovel->find('Salajogos');

        $features['Sauna Úmida'] = $imovel->find('Sauna');
        $features['Brinquedoteca'] = $imovel->find('Brinquedoteca');

        $features['Frente para o Mar'] = $imovel->find('Frentemar');
        $features['Vista para o Mar'] = $imovel->find('Vistamar');

        $features['Bicicletário'] = $imovel->find('Biciletario');

        $features['Gerador'] = $imovel->find('Gerador');
        $features['Portaria 24h'] = $imovel->find('Portaria24horas');
        $features['Gás Encanado'] = $imovel->find('Gas');

        return $features;
    }

    protected function parserImovelType(String $imovelType) : Array {
        $parsedImovelType = unicode_conversor($imovelType);
        $parsedImovelType = strtolower($parsedImovelType);
        $findImovelType = ImovelType::whereJsonContains('keywords', $parsedImovelType)->first();
        if (!$findImovelType) {
            $findImovelType = ImovelType::where('id', $this->getDefaultTypeId())->first();
            $this->adsTypesNFound[] = $parsedImovelType;
        }

        return [
            'TipoImovel' => $findImovelType->id,
            'NomeImovel' => $findImovelType->normal_name
        ];
    }

    protected function parserOfferType(String $offerType, $precoLocacao, $precoTemporada) : Int {
        return 0;
    }

    protected function parserDescription(String $description) : String {
        $cleanedDescription = remove_emoji($description);

        $cleanedDescription = trim($cleanedDescription);
        if (!preg_match('//u', $cleanedDescription)) {
            $cleanedDescription = utf8_encode($cleanedDescription);
        }

        return cleanAsc($cleanedDescription);
    }

    protected function parserGuarantee(String $guarantee) : Int {
        $guarantee = strtolower(trim(preg_replace('/(\v|\s)+/', ' ', $guarantee)));
        if (str_contains($guarantee, 'sem garantia')) {
            return 0;
        } elseif (str_contains($guarantee, 'depósito caução')) {
            return 1;
        } elseif (str_contains($guarantee, 'seguro fiança')) {
            return 2;
        } elseif (str_contains($guarantee, 'carta fiança')) {
            return 3;
        } elseif (str_contains($guarantee, 'fiador')) {
            return 4;
        } elseif (str_contains($guarantee, 'titulo de capitalização')) {
            return 5;
        } else {
            $this->adsGuaranteeNFound[] = $guarantee;
            return 0;
        }
    }

    protected function parserStatus(String $status) : Int {
        $statusCheck = strtolower($status);
        switch ($statusCheck) {
            case 'usado':
            case 'semi-novo':
                return 0;
            break;
            case 'novo':
                return 1;
            break;
            case 'construção':
                return 2;
            break;
            case 'lançamento':
                return 3;
            break;
            default:
                $this->adsStatusNFound[] = $statusCheck;
                return 0;
            break;
        }
    }

    protected function parserAreaUtil(String $area) : Int {
        return parseAreaNumber($area);
    }

    protected function parserAreaConstruida(String $area) : Int {
        return parseAreaNumber($area);
    }

    protected function parserAreaTotal(String $area) : Int {
        return parseAreaNumber($area);
    }

    protected function parserAreaTerreno(String $area) : Int {
        return parseAreaNumber($area);
    }

    protected function parserFeatures(Array $features) : Array {
        $parsedFeatures = [];
        foreach ($features as $indexStr => $feature) {
            if (count($feature)) {
                $isFeatureActived = $feature[0]->text();
                if ($isFeatureActived == '1') {
                    $findImovelFeature = ImovelFeatures::where('name', strtolower($indexStr))->first();
                    if (!$findImovelFeature) {
                        $this->featuresNFound[] = strtolower($indexStr);
                    } else {
                        $parsedFeatures[] = $findImovelFeature->feature_id;
                    }
                }
            }
        }

        return $parsedFeatures;
    }

    protected function parserUF(String $uf) : String {
        $foundedUF = unicode_conversor(trim($uf));
        if (strrpos($foundedUF, '-') !== false) {
            $foundedUF = strrpos($foundedUF, '-');
            $foundedUF = str_replace("-", "", $foundedUF);
            $foundedUFBk = $foundedUF;
            $foundedUF = BrazilianStates::where('abbreviation', strtoupper($foundedUF))->first();
            if ($foundedUF) {
                return $foundedUF->abbreviation;
            } else {
                $this->adsUFNFound[] = $foundedUFBk;
                return "";
            }
        } elseif ($foundedUF = BrazilianStates::where('state', $foundedUF)->first()) {
            return $foundedUF->abbreviation;
        } else {
            $this->adsUFNFound[] = $uf;
            return "";
        }
    }

    protected function parserImageUrl(Array $images) : Array {
        $toDownload = [];
        foreach ($images as $url) {
            $bckpUrl = $url;
            $url = trim(preg_replace('/\s\s+/', '', $url));
            $url = filter_var($url, FILTER_SANITIZE_URL);
            if (!($url = filter_var($url, FILTER_VALIDATE_URL))) {
                $this->adsIMGNFound[] = $this->imovelCode;
                continue;
            }

            $toDownload[] = $url;
        }

        return $toDownload;
    }

    protected function parserCEP(String $cep) : String {
        if ($cep) {
            $cep = trim(preg_replace('/\s\s+/', '', $cep));
            $cep = str_replace(".", "", str_replace(" ", "", $cep));

            if (stristr($cep, '-') == false) {
                $cep = substr_replace($cep, '-', 5, 0 );
            }

            return str_pad($cep, 9, "0");
        } else {
            $this->adsWNCep[] = $this->imovelCode;
        }

        return $cep;
    }

    protected function parserImovelTitleAndSlug(Array $imovel) : Array {
        $imovelTitle = $imovel['NomeImovel'];

        if (($imovel['TipoImovel'] <= 5) || ($imovel['TipoImovel'] >= 7 && $imovel['TipoImovel'] <= 9)
        || ($imovel['TipoImovel'] == 11) || ($imovel['TipoImovel'] >= 19 && $imovel['TipoImovel'] <= 22)) {
            if ($imovel['QtdDormitorios'] == 1) {
                $imovelTitle = "$imovelTitle com {$imovel['QtdDormitorios']} Quarto";
            } elseif($imovel['QtdDormitorios'] > 1) {
                $imovelTitle = "$imovelTitle com {$imovel['QtdDormitorios']} Quartos";
            }
        }

        switch ($imovel['TipoOferta']) {
            case 1:
            $imovelTitle = "$imovelTitle à Venda, ";
            break;
            case 2:
                $imovelTitle = "$imovelTitle para Alugar, ";
            break;
            case 3:
                    $imovelTitle = "$imovelTitle à Venda ou Locação, ";
            break;
            case 4:
                $imovelTitle = "$imovelTitle para Temporada, ";
            break;
            case 5:
                $imovelTitle = "$imovelTitle à Venda, Locação ou Temporada, ";
            break;
            case 6:
                $imovelTitle = "$imovelTitle à Venda ou Temporada, ";
            break;
            case 7:
                $imovelTitle = "$imovelTitle para Alugar ou Temporada, ";
            break;
        }

        if ($imovel['AreaUtil'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaUtil'], 0, ",", ".")." m²";
        } elseif ($imovel['AreaConstruida'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaConstruida'], 0, ",", ".")." m²";
        } elseif ($imovel['AreaTotal'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaTotal'], 0, ",", ".")." m²";
        } elseif ($imovel['AreaTerreno'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaTerreno'], 0, ",", ".")." m²";
        }

        if ($imovel['Bairro'] != null) {
            $imovelTitle = $imovelTitle." em ". ucwords(mb_strtolower($imovel['Bairro']));
            if ($imovel['Cidade'] != null) {
                $imovelTitle = $imovelTitle." - ".ucwords(mb_strtolower($imovel['Cidade']));
            }
        } elseif ($imovel['Cidade'] != null) {
            $imovelTitle = $imovelTitle." em ".ucwords(mb_strtolower($imovel['Cidade']));
        }

        return [
            'ImovelTitle' => $imovelTitle,
            'ImovelSlug' => Str::slug($imovelTitle)
        ];
    }

    protected function parserNegotiation(Array $imovel) : Int {
        $negotiationId = -1;
        if ($imovel['TipoOferta']) {
            $negotiationId = $imovel['TipoOferta'];
        } else {
            if ($imovel['PrecoLocacao']) {
                $negotiationId = 2;
            }

            if($imovel['PrecoVenda']) {
                $negotiationId = 1;
            }
        }

        if ($negotiationId == -1) {
            $this->adsNegotiationNFound[] = $this->imovelCode;
        }

        return $negotiationId;
    }

    protected function parserYoutubeVideo(String $url) : Mixed {
        if (stristr($url, 'https://www.youtube.com/watch?v=') === FALSE && stristr($url, 'https://youtu.be/') === FALSE) {
            $this->adsYtNFound[] = $this->imovelCode;
            return NULL;
        }

        return $url;
    }

    protected function parserValorM2(Int $precoVenda, Int $areaUtil) : Mixed {
        if ($precoVenda > 0 && $areaUtil > 0) {
            return $precoVenda / $areaUtil;
        }

        return NULL;
    }


    protected function insertXmlData() : Void {
        $user_id = $this->integration->user->id;
        $userAnuncios = Anuncio::with(['endereco', 'condominiumData', 'anuncioBeneficio', 'gallery'])
        ->where('user_id', $user_id)
        ->where('xml', 1)
        ->orderBy('id', 'ASC')
        ->get();

        $anuncioIds = Arr::pluck($userAnuncios, 'id');
        $condominiums = Imovel::select('id', 'cep', 'endereco')
        ->whereIn('cep', Arr::pluck($this->data, 'CEP'))
        ->get();

        $cities = Arr::map(Arr::pluck($this->data, 'Cidade'), function ($value, $key) {
            return Str::slug($value);
        });

        $districts = Arr::map(Arr::pluck($this->data, 'Bairro'), function ($value, $key) {
            return Str::slug($value);
        });

        $districts = Bairro::select('id', 'slug', 'slug_cidade', 'uf_true as uf')
        ->whereIn('slug_cidade', $cities)
        ->whereIn('slug', $districts)
        ->get();

        $condominiumsData = CondominiumData::whereIn('ad_id', $anuncioIds)->get();
        $anunciosBenefits = AnuncioBeneficio::whereIn('anuncio_id', $anuncioIds)->get();

        unset($cities);
        unset($anuncioIds);

        foreach ($this->data as $index => $imovel) {
            if ($imovel['TipoOferta'] == -1 || $imovel['NegotiationId'] == -1) {
                continue;
            }

            $this->quantityMade++;

            $isNewAnuncio = false;

            $cepToFind = $imovel['CEP'];
            $location = ucwords(mb_strtolower($imovel['Endereco'])).", ".$imovel['Numero'];
            $condominium = $condominiums->filter(function($item) use ($cepToFind, $location) {
                return $item->cep == $cepToFind && false !== stristr($item->endereco, $location);
            })->first();

            $condominioId = 0;
            if ($condominium) {
                $condominioId = $condominium->id;
            }

            $newAnuncioInfo = [
                'user_id' => $user_id,
                'status' => "ativado",
                'type_id' => $imovel['TipoImovel'],
                'condominio_id' => $condominioId,
                'new_immobile' => $imovel['Novo'],
                'negotiation_id' => $imovel['NegotiationId'],
                'condominio_mes' => $imovel['PrecoCondominio'],
                'valor' => $imovel['PrecoVenda'],
                'valor_aluguel' => $imovel['PrecoLocacao'],
                'valor_temporada' => $imovel['PrecoTemporada'],
                'rental_guarantee' => $imovel['GarantiaAluguel'],
                'area_total' => $imovel['AreaTotal'],
                'area_util' => $imovel['AreaUtil'],
                'area_terreno' => $imovel['AreaTerreno'],
                'area_construida' => $imovel['AreaConstruida'],
                'bedrooms' => $imovel['QtdDormitorios'],
                'suites' => $imovel['QtdSuites'],
                'bathrooms' => $imovel['QtdBanheiros'],
                'codigo' => $imovel['CodigoImovel'],
                'parking' => $imovel['QtdVagas'],
                'description' => $imovel['Descricao'],
                'slug' => $imovel['ImovelSlug'],
                'title' => $imovel['ImovelTitle'],
                'status' => "ativado",
                'usage_type_id' => 1,
                'iptu' => $imovel['ValorIPTU'],
                'xml' => 1,
                'spotlight' => $imovel['Spotlight'],
                'subtitle' => $imovel['Subtitle'],
                'exchange' => $imovel['Permuta'],
                'youtube' => $imovel['Video']
            ];

            $imovelId = 0;
            $existingImovel = $userAnuncios->whereStrict('codigo', $imovel['CodigoImovel'])->last();
            if ($existingImovel) {
                if ($this->isDifferentImovel($existingImovel, $newAnuncioInfo)) {
                    $newAnuncioInfo['updated_at'] = Carbon::now('America/Sao_Paulo');
                    $existingImovel->update($newAnuncioInfo);
                }

                $imovelId = $existingImovel->id;
            } else {
                $newAnuncioInfo['created_at'] = Carbon::now('America/Sao_Paulo');
                $newAnuncio = Anuncio::create($newAnuncioInfo);
                $isNewAnuncio = true;
                $imovelId = $newAnuncio->id;
            }

            if ($condominium) {
                $builder = NULL;
                if ($imovel['Construtora']) {
                    $builder = DB::table('builders')->where('name', $imovel['Construtora'])->first();
                    if ($builder) {
                        $builder = $builder->id;
                    }
                }

                $condominiumData = [
                    "condominiun_id" => $condominium->id,
                    "ad_id" => $imovelId,
                    "builder_id" => $builder,
                    "number_of_floors" => $imovel['Andares'],
                    "units_per_floor" => $imovel['UnidadesAndar'],
                    "number_of_towers" => $imovel['Torres'],
                    "construction_year" => $imovel['AnoConstrucao'],
                    "terrain_size" => $imovel['AreaTerreno']
                ];

                $condominiumDataRet = $condominiumsData->filter(function($item) use ($imovelId) {
                    return $item->ad_id == $imovelId;
                })->first();

                if ($condominiumDataRet) {
                    if ($this->isDifferentCondominium($condominiumDataRet, $condominiumData)) {
                        $condominiumDataRet->update($condominiumData);
                    }
                } else {
                    CondominiumData::insert($condominiumData);
                }
            }

            $imovelFeatures = $anunciosBenefits->filter(function($item) use ($imovelId) {
                return $item->anuncio_id == $imovelId;
            });

            $amountFeatures = $imovelFeatures ? $imovelFeatures->count() : 0;
            $totalFeatures = count($imovel['Features']);
            if ($totalFeatures && $totalFeatures != $amountFeatures) {
                $features = [];
                AnuncioBeneficio::where('anuncio_id', $imovelId)->delete();
                foreach ($imovel['Features'] as $feature) {
                    $featureToInsert = [
                        'anuncio_id' =>  $imovelId,
                        'beneficio_id' => $feature
                    ];

                    $features[] = $featureToInsert;
                }

                AnuncioBeneficio::insert($features);
            }

            $endereco = [
                "anuncio_id" => $imovelId,
                "mostrar_endereco" => $imovel['MostrarEndereco'],
                "cep" => $imovel['CEP'],
                "cidade" => ucwords(mb_strtolower($imovel['Cidade'])),
                "slug_cidade" => $imovel['CidadeSlug'],
                "uf" => $imovel['UF'],
                "bairro" => ucwords(mb_strtolower($imovel['Bairro'])),
                "slug_bairro" => $imovel['BairroSlug'],
                "logradouro" => ucwords(mb_strtolower($imovel['Endereco'])),
                "numero" => $imovel['Numero'],
                "bairro_comercial" => ucwords(mb_strtolower($imovel['BairroComercial'] ?? "")),
                "latitude" => $imovel['Latitude'],
                "longitude" => $imovel['Longitude'],
                "created_at" => Carbon::now()->toDateTimeString()
            ];

            $ufToFind = $imovel['UF'];
            $citySlugToFind = $imovel['CidadeSlug'];
            $bairroSlugToFind = $imovel['BairroSlug'];

            $validLocation = $districts->filter(function($item) use ($ufToFind, $citySlugToFind, $bairroSlugToFind) {
                return $item->uf == $ufToFind
                && $item->slug_cidade == $citySlugToFind
                && $item->slug == $bairroSlugToFind;
            })->first();

            if ($validLocation) {
                $endereco['valid_location'] = $validLocation->id;
            } else {
                $endereco['valid_location'] = 0;
            }

            if ($existingImovel) {
                if ($this->isDifferentLocation($existingImovel, $endereco)) {
                    AnuncioEndereco::where('anuncio_id', $imovelId)->update($endereco);
                }
            } else {
                AnuncioEndereco::insert($endereco);
            }

            if ($this->isManual) {
                echo "----------------------------------------------------\n",
                "Imóvel Nº: $index - Anúncio Código: {$imovel['CodigoImovel']}.\n",
                "Integração: {Total de Imóveis: {$this->imoveisCount} - Total Feitos: {$this->quantityMade} - ID do Usuário: {$user_id} - ID da Integração: {$this->integration->id}\n";
            }

            if ($isNewAnuncio || $this->updateType != Integracao::XML_STATUS_IN_DATA_UPDATE) {
                if ($isNewAnuncio) {
                    if (count($imovel['images'])) {
                        $this->imagesExpected += count($imovel['images']);
                        $imagesToInsert = [];
                        $imagesCounter = 0;
                        foreach ($imovel['images'] as $url) {
                            if ($imagesCounter <= 20) {
                                try {
                                    $imageFileName = 'integration/' . md5($user_id . $imovelId . basename($url)) . '.webp';
                                    $s3Path = "images/{$imageFileName}";
                                    $context = stream_context_create(
                                        array(
                                            "http" => array(
                                                "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 OPR/107.0.0.0\r\n" . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7\r\n"
                                            )
                                        )
                                    );
                                    $fileData = file_get_contents($url, false, $context);

                                    $imageObject = Image::make($fileData);
                                    $originalData = $imageObject->encode('webp', 85)->getEncoded();
                                    $uploadStartTime = microtime(true);
                                    Storage::disk('do_spaces')->put($s3Path, $originalData, 'public');
                                    $uploadTime = microtime(true) - $uploadStartTime;

                                    $basePath = public_path("images/$imageFileName");
                                    $imageObject->save($basePath);

                                    $this->reduceImage($s3Path, $imageFileName);

                                    $imagesToInsert[] = [
                                        "anuncio_id" => $imovelId,
                                        "name" => $imageFileName,
                                        "created_at" => Carbon::now()->toDateTimeString()
                                    ];

                                    $imagesCounter++;
                                } catch(\Exception $e) {
                                    $this->toLog[] = "Exception na hora de inserir a imagem - Error: \"". $e->getMessage() . "\" - Imóvel ID(DB): \"$imovelId\" - URL(no XML depois de ser parseado): \"$url\" - CodigoImovel(no XML) do Imóvel: \"{$imovel['CodigoImovel']}\".";
                                }
                            }
                        }

                        if ($imagesCounter) {
                            $this->imagesInserted += $this->insertOrUpdateImages($imovelId, $imagesToInsert, 'inserted');
                            if ($this->isManual) {
                                echo "Imagem Nº: $index - Anúncio Código: {$imovel['CodigoImovel']}.\n";
                            }
                        }
                    }
                } else {
                    $oldImages = $existingImovel->gallery;
                    $toDownload = [];
                    $toCompare = [];

                    foreach ($imovel['images'] as $key => $url) {
                        $imageFileName = 'integration/' . md5($user_id . $imovelId . basename($url)) . '.webp';

                        $toCompare[] = $imageFileName;

                            $toDownload[] = ['url' => $url, 'imageFileName' => $imageFileName];

                    }

                    if (count($toDownload)) {
                        $this->imagesExpected += count($toDownload);
                        $toDelete = $oldImages->whereNotIn('name', $toCompare);
                        foreach ($toDelete as $key => $imageToDelete) {
                            $this->deleteIntegrationImage($imageToDelete->name);
                            $imageToDelete->delete();
                        }

                        $imagesToInsert = [];
                        $imagesCounter = 0;
                        foreach ($toDownload as $toDown) {
                            if ($imagesCounter <= 20) {
                                try {
                                    $imageFileName = $toDown['imageFileName'];
                                    $url = $toDown['url'];
                                    $s3Path = "images/{$imageFileName}";
                                    $context = stream_context_create(
                                        array(
                                            "http" => array(
                                                "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 OPR/107.0.0.0\r\n" . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7\r\n"
                                            )
                                        )
                                    );
                                    $fileData = file_get_contents($url, false, $context);

                                    $imageObject = Image::make($fileData);
                                    $originalData = $imageObject->encode('webp', 85)->getEncoded();
                                    $uploadStartTime = microtime(true);
                                    Storage::disk('do_spaces')->put($s3Path, $originalData, 'public');
                                    $uploadTime = microtime(true) - $uploadStartTime;

                                    $basePath = public_path("images/$imageFileName");
                                    $imageObject->save($basePath);

                                    $this->reduceImage($s3Path, $imageFileName);

                                    $imagesToInsert[] = [
                                        "anuncio_id" => $imovelId,
                                        "name" => $imageFileName,
                                        "created_at" => Carbon::now()->toDateTimeString()
                                    ];

                                    $imagesCounter++;
                                } catch(\Exception $e) {
                                    $this->toLog[] = "Exception na hora do update da imagem - Error: \"". $e->getMessage() . "\" - Imóvel ID(DB): \"$imovelId\" - URL(no XML depois de ser parseado): \"$url\" - CodigoImovel(no XML) do Imóvel: \"{$imovel['CodigoImovel']}\".";
                                }
                            }
                        }

                        if ($imagesCounter) {
                            $this->imagesInserted += $this->insertOrUpdateImages($imovelId, $imagesToInsert, 'updated');
                            if ($this->isManual) {
                                echo "Imagem(update) Nº: $index - Anúncio Código: {$imovel['CodigoImovel']}.\n";
                            }
                        }
                    }
                }
            }
        }
        $anuncioService = new AnuncioService;
        $anuncioService->validateAdPoints($user_id);

        $this->logDone();
        $this->finalizeIntegration('Union', $this->data);
    }
}