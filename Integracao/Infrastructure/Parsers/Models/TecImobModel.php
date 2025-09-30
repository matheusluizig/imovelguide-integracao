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

class TecImobModel extends XMLBaseParser
{
    public function __construct(Document $xml, Integracao $integration)
    {
        parent::__construct($xml, $integration);
        $this->startIntegration();
    }



    protected function parserXml(): void
    {
        $imoveis = $this->getXml()->find('Imovel');
        $this->imoveisCount = count($imoveis);
        if (!$this->imoveisCount) {
            $imoveis = $this->getXml()->find('imovel');
            $this->imoveisCount = count($imoveis);
        }

        foreach ($imoveis as $index => $imovel) {

            $data = [];
            $data['CodigoImovel'] = '';
            if ($imovel->has('CodigoImovel')) {
                $codigoImovel = $imovel->find('CodigoImovel');
                $data['CodigoImovel'] = count($codigoImovel) > 0 ? $codigoImovel[0]->text() : '';
            } elseif ($imovel->has('Codigo')) {
                $codigo = $imovel->find('Codigo');
                $data['CodigoImovel'] = count($codigo) > 0 ? $codigo[0]->text() : '';
            }

            $data['Subtitle'] = NULL;
            $subTitle = $imovel->find('Titulo');
            if (count($subTitle)) {
                $data['Subtitle'] = $subTitle[0]->text();
            }
            if (!$data['Subtitle']) {
                $subTitle = $imovel->find('TituloImovel');
                if (count($subTitle)) {
                    $data['Subtitle'] = $subTitle[0]->text();
                }
            }
            if (!$data['Subtitle']) {
                $subTitle = $imovel->find('TituloAnuncio');
                if (count($subTitle)) {
                    $data['Subtitle'] = $subTitle[0]->text();
                }
            }

            $data['Descricao'] = '';
            if ($imovel->has('Observacao')) {
                $observacao = $imovel->find('Observacao');
                $data['Descricao'] = count($observacao) > 0 ? $observacao[0]->text() : '';
            }

            $data['PrecoVenda'] = 0;
            if ($imovel->has('PrecoVenda')) {
                $precoVenda = $imovel->find('PrecoVenda');
                $data['PrecoVenda'] = count($precoVenda) > 0 ? $precoVenda[0]->text() : 0;
                if ($data['PrecoVenda'] == '') {
                    $data['PrecoVenda'] = 0;
                }
            }
            if (empty($data['PrecoVenda'])) {
                $data['PrecoVenda'] = 0;
            }

            $data['PrecoLocacao'] = 0;
            if ($imovel->has('PrecoLocacao')) {
                $precoLocacao = $imovel->find('PrecoLocacao');
                $data['PrecoLocacao'] = count($precoLocacao) > 0 ? $precoLocacao[0]->text() : 0;
                if ($data['PrecoLocacao'] == '') {
                    $data['PrecoLocacao'] = 0;
                }
            }

            $data['PrecoTemporada'] = NULL;
            if ($imovel->has('PrecoLocacaoTemporada')) {
                $precoTemporada = $imovel->find('PrecoLocacaoTemporada');
                $data['PrecoTemporada'] = count($precoTemporada) > 0 ? $precoTemporada[0]->text() : NULL;
                if ($data['PrecoTemporada'] == '') {
                    $data['PrecoTemporada'] = NULL;
                }
            }

            $data['TipoOferta'] = -1;
            $data['PublicarValores'] = NULL;
            if ($imovel->has('Publicavalores')) {
                $publicarValores = $imovel->find('Publicavalores');
                $data['PublicarValores'] = count($publicarValores) > 0 ? $publicarValores[0]->text() : NULL;
            }
            $data['Spotlight'] = 0;
            $data['Highlighted'] = NULL;

            if ($imovel->has('TipoOferta')) {
                $tipoOferta = $imovel->find('TipoOferta');
                $data['Highlighted'] = count($tipoOferta) > 0 && in_array($tipoOferta[0]->text(), ['STANDARD', 'PREMIUM', 'SUPER_PREMIUM', 1]) ? 1 : 0;

            }

            $data['GarantiaAluguel'] = 0;
            if ($imovel->has('GarantiaLocacao')) {
                $garantiaLocacao = $imovel->find('GarantiaLocacao');
                $data['GarantiaAluguel'] = count($garantiaLocacao) > 0 ? $garantiaLocacao[0]->text() : 0;
            }
            if (!$data['GarantiaAluguel'] && $imovel->has('Garantia')) {
                $garantia = $imovel->find('Garantia');
                $data['GarantiaAluguel'] = count($garantia) > 0 ? $garantia[0]->text() : 0;
            }

            $data['ValorIPTU'] = NULL;
            if ($imovel->has('ValorIPTU')) {
                $valorIPTU = $imovel->find('ValorIPTU');
                $data['ValorIPTU'] = count($valorIPTU) > 0 ? $valorIPTU[0]->text() : NULL;
            }

            $data['PrecoCondominio'] = NULL;
            if ($imovel->has('PrecoCondominio')) {
                $precoCondominio = $imovel->find('PrecoCondominio');
                $data['PrecoCondominio'] = count($precoCondominio) > 0 ? $precoCondominio[0]->text() : NULL;
                if ($data['PrecoCondominio'] == '') {
                    $data['PrecoCondominio'] = NULL;
                }
            }

            $data['Permuta'] = 0;
            if ($imovel->has('AceitaPermuta')) {
                $aceitaPermuta = $imovel->find('AceitaPermuta');
                $data['Permuta'] = count($aceitaPermuta) > 0 ? intval($aceitaPermuta[0]->text()) : 0;
            }

            $data['Andares'] = NULL;

            $data['UnidadesAndar'] = NULL;
            if ($imovel->has('QtdAndar')) {
                $qtdAndar = $imovel->find('QtdAndar');
                $data['UnidadesAndar'] = count($qtdAndar) > 0 ? $qtdAndar[0]->text() : NULL;
            }

            $data['Torres'] = NULL;

            $data['Construtora'] = 0;
            if ($imovel->has('Construtora')) {
                $construtora = $imovel->find('Construtora');
                $data['Construtora'] = count($construtora) > 0 ? $construtora[0]->text() : 0;
            }

            $data['MostrarEndereco'] = 2;

            $data['AreaTotal'] = NULL;
            if ($imovel->has('AreaTotal')) {
                $areaTotal = $imovel->find('AreaTotal');
                $data['AreaTotal'] = count($areaTotal) > 0 ? $areaTotal[0]->text() : NULL;
            }

            $data['TipoImovel'] = 'outros';
            if ($imovel->has('TipoImovel')) {
                $tipoImovel = $imovel->find('TipoImovel');
                $data['TipoImovel'] = count($tipoImovel) > 0 ? $tipoImovel[0]->text() : 'outros';
            }

            $data['NomeImovel'] = "";
            $data['Novo'] = 0;
            if ($imovel->has('StatusComercial')) {
                $statusComercial = $imovel->find('StatusComercial');
                $data['Novo'] = count($statusComercial) > 0 ? $statusComercial[0]->text() : 0;
            }

            $data['AreaUtil'] = 0;
            if ($imovel->has('AreaUtil')) {
                $areaUtil = $imovel->find('AreaUtil');
                $data['AreaUtil'] = count($areaUtil) > 0 ? $areaUtil[0]->text() : 0;
            }
            if (empty($data['AreaUtil'])) {
                $data['AreaUtil'] = 0;
            }

            $data['AreaTerreno'] = 0;
            if ($imovel->has('AreaDoTerreno')) {
                $areaTerreno = $imovel->find('AreaDoTerreno');
                $data['AreaTerreno'] = count($areaTerreno) > 0 ? $areaTerreno[0]->text() : 0;
            }
            if ($data['AreaTerreno'] == '') {
                $data['AreaTerreno'] = 0;
            }

            $data['AreaConstruida'] = NULL;

            $data['AnoConstrucao'] = 0;
            if ($imovel->has('AnoConstrucao')) {
                $anoConstrucao = $imovel->find('AnoConstrucao');
                $data['AnoConstrucao'] = count($anoConstrucao) > 0 ? $anoConstrucao[0]->text() : 0;
            }

            $data['QtdDormitorios'] = 0;
            if ($imovel->has('QtdDormitorios')) {
                $qtdDormitorios = $imovel->find('QtdDormitorios');
                $data['QtdDormitorios'] = count($qtdDormitorios) > 0 ? $qtdDormitorios[0]->text() : 0;
            }

            $data['QtdSuites'] = NULL;
            if ($imovel->has('QtdSuites')) {
                $qtdSuites = $imovel->find('QtdSuites');
                $data['QtdSuites'] = count($qtdSuites) > 0 ? $qtdSuites[0]->text() : NULL;
            }

            $data['QtdBanheiros'] = 0;
            if ($imovel->has('QtdBanheiros')) {
                $qtdBanheiros = $imovel->find('QtdBanheiros');
                $data['QtdBanheiros'] = count($qtdBanheiros) > 0 ? $qtdBanheiros[0]->text() : 0;
            }

            $data['QtdVagas'] = 0;
            if ($imovel->has('QtdVagas')) {
                $qtdVagas = $imovel->find('QtdVagas');
                $data['QtdVagas'] = count($qtdVagas) > 0 ? $qtdVagas[0]->text() : 0;
            }

            $data['Features'] = $this->getFeatures($imovel);

            $data['UF'] = NULL;
            if ($imovel->has('UF')) {
                $uf = $imovel->find('UF');
                $data['UF'] = count($uf) > 0 ? $uf[0]->text() : NULL;
            }
            if (!$data['UF'] && $imovel->has('Estado')) {
                $estado = $imovel->find('Estado');
                $data['UF'] = count($estado) > 0 ? $estado[0]->text() : NULL;
            }
            if (!$data['UF'] && $imovel->has('estado')) {
                $estado = $imovel->find('estado');
                $data['UF'] = count($estado) > 0 ? $estado[0]->text() : NULL;
            }

            $data['Cidade'] = '';
            if ($imovel->has('Cidade')) {
                $cidade = $imovel->find('Cidade');
                $data['Cidade'] = count($cidade) > 0 ? $cidade[0]->text() : '';
            }

            $bairro = $imovel->find('Bairro');
            $data['Bairro'] = count($bairro) > 0 ? $bairro[0]->text() : '';
            $data['BairroComercial'] = NULL;

            $data['CEP'] = 0;

            $cep = $imovel->find('CEP');
            if (count($cep)) {
                $data['CEP'] = $cep[0]->text();
            }

            $data['Endereco'] = '';
            if ($imovel->has('Endereco')) {
                $endereco = $imovel->find('Endereco');
                $data['Endereco'] = count($endereco) > 0 ? $endereco[0]->text() : '';
            }

            $data['Numero'] = NULL;
            if ($imovel->has('Numero')) {
                $numero = $imovel->find('Numero');
                $data['Numero'] = count($numero) > 0 ? $numero[0]->text() : NULL;
            }

            $data['Complemento'] = NULL;
            if ($imovel->has('Complemento')) {
                $complemento = $imovel->find('Complemento');
                $data['Complemento'] = count($complemento) > 0 ? $complemento[0]->text() : NULL;
            }
            if (!$data['Complemento'] && $imovel->has('ComplementoEndereco')) {
                $complementoEndereco = $imovel->find('ComplementoEndereco');
                $data['Complemento'] = count($complementoEndereco) > 0 ? $complementoEndereco[0]->text() : NULL;
            }

            $data['Latitude'] = NULL;
            if ($imovel->has('Latitude')) {
                $latitude = $imovel->find('Latitude');
                $data['Latitude'] = count($latitude) > 0 ? $latitude[0]->text() : NULL;
            }

            $data['Longitude'] = NULL;
            if ($imovel->has('Longitude')) {
                $data['Longitude'] = $imovel->find('Longitude')[0]->text();
            }

            $data['Video'] = NULL;
            $videos = $imovel->find('Videos');
            if (count($videos)) {
                foreach ($videos[0]->children() as $video) {
                    if ($video->has('Url')) {
                        $data['Video'] = $video->find('Url')[0]->text();
                        break;
                    } else {
                        $data['Video'] = $video->text();
                        break;
                    }
                }
            }

            $data['images'] = [];

                $images = $imovel->find('Fotos');
                $imagesCounter = 0;
                if (count($images)) {
                    foreach ($images[0]->children() as $media) {
                        $image = [];
                        if ($media->has('URLArquivo')) {
                            $image = $media->find('URLArquivo');
                        } elseif ($media->find('URL')) {
                            $image = $media->find('URL');
                        }

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

            $imovel['TipoOferta'] = $this->getOfferType($imovel['PrecoVenda'], $imovel['PrecoLocacao'], $imovel['PrecoTemporada']);

            if ($imovel['PublicarValores']) {
                $imovel = $this->parserPublishValues($imovel);
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

        $this->data = collect($this->data);
        $duplicatesEntry = $this->data->duplicates('CodigoImovel');
        foreach ($duplicatesEntry as $key => $value) {
            $this->data->forget($key);
        }

        if ($duplicatesEntry->count()) {
            $duplicatesIds = implode(" - ", $duplicatesEntry->toArray());
            $this->toLog[] = "Os seguintes imóveis não foram inseridos por duplicidade(Baseado no código do imóvel): {$duplicatesIds}.";
        }
    }

    private function getOfferType($precoVenda, $precoLocacao, $precoTemporada) : Int {
        if ($precoVenda && $precoLocacao && $precoTemporada) {
            return 5;
        } elseif ($precoVenda) {
            if ($precoLocacao) {
                return 3;
            } elseif ($precoTemporada) {
                return 6;
            } else {
                return 1;
            }
        } elseif ($precoLocacao) {
            if ($precoTemporada) {
                return 7;
            } else {
                return 2;
            }
        } elseif ($precoTemporada) {
            return 4;
        } else {
            return 1;
        }
    }

    private function getFeatures($imovel) : Array {
        $features = [];

        $features['Piscina'] = $imovel->find('Piscina');
        $features['Ar Condicionado'] = $imovel->has('Arcondicionado') ? $imovel->find('Arcondicionado') : $imovel->find('ArCondicionado');

        $features['Sacada'] = $imovel->find('Sacada');
        $features['Depósito'] = $imovel->find('Deposito') ? $imovel->find('Deposito') : $imovel->find('DepositoSubsolo');

        $features['Churrasqueira'] = $imovel->find('Churrasqueira');
        $features['Elevador'] = $imovel->find('QtdElevador');
        $features['Academia'] = $imovel->find('Academia');
        $features['Salão de Festa'] = $imovel->find('SalaoFestas');
        $features['Playground'] = $imovel->find('Playground');

        $features['Quadra Poliesportiva'] = $imovel->find('QuadraPoliEsportiva');
        $features['Banheiro para Empregada'] = $imovel->has('WCEmpregada') ? $imovel->find('WCEmpregada') : $imovel->find('QuartoWCEmpregada');
        $features['Dormitório para Empregada'] = $imovel->has('DormitorioEmpregada') ? $imovel->find('DormitorioEmpregada') : $imovel->find('QuartoWCEmpregada');
        $features['Varanda Gourmet'] = $imovel->find('VarandaGourmet');
        $features['Varanda'] = $imovel->find('Varanda');

        $features['Armário de Cozinha Planejado'] = $imovel->find('Armariocozinha');
        $features['Armário do Quarto Planejado'] = $imovel->find('ArmarioDormitorio');

        $features['Piscina Infantil Aberta'] = $imovel->find('Piscinainfantil');

        $features['Quadra de Tênis'] = $imovel->find('QuadraTenis');
        $features['Quadra de Squash'] = $imovel->find('Quadrasquash');
        $features['Campo de Futebol'] = $imovel->find('CampoFutebol');

        $features['Salão de Jogos'] = $imovel->find('SalaoJogos');

        $features['Sauna Úmida'] = $imovel->find('Sauna');
        $features['Brinquedoteca'] = $imovel->find('Brinquedoteca');

        $features['Frente para o Mar'] = $imovel->find('FrenteMar');
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
    }

    private function parserPublishValues(Array $imovel) : Int {
        $publishType = intval($imovel['PublicarValores']);
        if (!$publishType) {
            return $imovel;
        }

        switch ($publishType) {
            case 2:
                $imovel['PrecoLocacao'] = "";
                $imovel['PrecoTemporada'] = "";
                break;
            case 3:
                $imovel['PrecoVenda'] = "";
                break;
            case 4:
                $imovel['PrecoVenda'] = "";
                $imovel['PrecoLocacao'] = "";
                $imovel['PrecoTemporada'] = "";
                break;
        }

        return $imovel;
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
        } elseif (str_contains($guarantee, 'aluguel antecipado') || str_contains($guarantee, 'caução')) {
            return 1;
        } elseif (str_contains($guarantee, 'seguro fiança')) {
            return 2;
        } elseif (str_contains($guarantee, 'carta fiança')) {
            return 3;
        } elseif (str_contains($guarantee, 'fiador')) {
            return 4;
        } elseif (str_contains($guarantee, 'capitalização')) {
            return 5;
        } elseif (str_contains($guarantee, 'fiança empresarial')) {
            return 6;
        } else {
            $this->adsGuaranteeNFound[] = $guarantee;
            return 0;
        }
    }

    protected function parserStatus(String $status) : Int {
        $statusCheck = strtolower($status);
        switch ($statusCheck) {
            case 'usado':
            case 'revenda':
            case 'semi-novo':
                return 0;
            break;
            case 'pronto para morar':
            case 'novo':
                return 1;
            break;
            case 'construção':
                return 2;
            break;
            case 'lançamento':
            case 'futuro Lançamento':
            case 'pré-lançamento':
            case 'últimas unidades':
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
            if (gettype($feature) == 'array' && count($feature)) {
                $isFeatureActived = $feature[0]->text();
                if (intval($isFeatureActived) > 0) {
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
                'ig_highlight' => $imovel['Highlighted'],
                'subtitle' => $imovel['Subtitle'],
                'exchange' => $imovel['Permuta'],
                'youtube' => $imovel['Video']
            ];

            $imovelId = 0;
            $existingImovel = $userAnuncios->whereStrict('codigo', $imovel['CodigoImovel'])->last();
            if ($existingImovel) {
                if ($existingImovel->status === 'inativado') {
                    continue;
                }
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
                            $this->insertOrUpdateImages($imovelId, $imagesToInsert, 'inserted');
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
                            $this->insertOrUpdateImages($imovelId, $imagesToInsert, 'updated');
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
        $this->finalizeIntegration('TecImob', $this->data);
    }
}