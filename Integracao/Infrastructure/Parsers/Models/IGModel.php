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
use Illuminate\Support\Facades\Log;
use App\Services\InviteService;

class IGModel extends XMLBaseParser {
    public function __construct(Document $xml, Integracao $integration) {
        parent::__construct($xml, $integration);
        $this->startIntegration();
    }



    protected function parserXml() : Void {
        $imoveis = $this->getXml()->find('Property');
        $this->imoveisCount = count($imoveis);

        foreach ($imoveis as $index => $imovel) {

            $data = [];
            $propertyCode = $imovel->find('PropertyCode');
            $data['CodigoImovel'] = count($propertyCode) > 0 ? $propertyCode[0]->text() : '';
            $data['Subtitle'] = NULL;
            $subTitle = $imovel->find('Title');
            if (count($subTitle)) {
                $data['Subtitle'] = $subTitle[0]->text();
            }

            $data['Descricao'] = '';
            if ($imovel->has('Description')) {
                $description = $imovel->find('Description');
                $data['Descricao'] = count($description) > 0 ? $description[0]->text() : '';
            }

            $transaction = $imovel->find('Transaction');
            $transaction = count($transaction) > 0 ? $transaction[0] : null;

            $transactionType = $transaction ? $transaction->find('TransactionType') : [];
            $data['TipoOferta'] = $transaction && count($transactionType) > 0 ? $transactionType[0]->text() : '';

            $data['PrecoVenda'] = 0;
            if ($transaction && $transaction->has('ListPrice')) {
                $listPrice = $transaction->find('ListPrice');
                $data['PrecoVenda'] = count($listPrice) > 0 ? $listPrice[0]->text() : 0;
            }
            if (empty($data['PrecoVenda'])) {
                $data['PrecoVenda'] = 0;
            }

            $data['PrecoLocacao'] = 0;
            $data['LocationWeekly'] = false;
            if ($transaction && $transaction->has('RentPrice')) {
                $rentPrice = $transaction->find('RentPrice');
                $rentPrice = count($rentPrice) > 0 ? $rentPrice[0] : null;
                $data['PrecoLocacao'] = $rentPrice ? $rentPrice->text() : 0;

                $locationWeekly = $rentPrice->getAttribute('period');
                if ($locationWeekly && strtolower($locationWeekly) == "weekly") {
                    $data['LocationWeekly'] = true;
                }
            }

            $data['PrecoTemporada'] = NULL;
            if ($transaction && $transaction->has('SeasonPrice')) {
                $seasonPrice = $transaction->find('SeasonPrice');
                $data['PrecoTemporada'] = count($seasonPrice) > 0 ? $seasonPrice[0]->text() : NULL;
            }
            $data['Spotlight'] = 0;
            $data['Highlighted'] = NULL;
            if ($transaction && $transaction->has('Highlighted')) {
                $highlighted = $transaction->find('Highlighted');
                $data['Highlighted'] = count($highlighted) > 0 ? intval($highlighted[0]->text()) : NULL;
            }

            $data['GarantiaAluguel'] = NULL;
            if ($transaction && $transaction->has('RentalGuarantee')) {
                $rentalGuarantee = $transaction->find('RentalGuarantee');
                $data['GarantiaAluguel'] = count($rentalGuarantee) > 0 ? $rentalGuarantee[0]->text() : NULL;
            }

            $data['ValorIPTU'] = NULL;
            if ($transaction && $transaction->has('YearlyTax')) {
                $yearlyTax = $transaction->find('YearlyTax');
                $data['ValorIPTU'] = count($yearlyTax) > 0 ? $yearlyTax[0]->text() : NULL;
            }

            $data['PrecoCondominio'] = NULL;
            if ($transaction && $transaction->has('PropertyAdministrationFee')) {
                $propertyAdministrationFee = $transaction->find('PropertyAdministrationFee');
                $data['PrecoCondominio'] = count($propertyAdministrationFee) > 0 ? $propertyAdministrationFee[0]->text() : NULL;
            }

            $data['Permuta'] = 0;
            if ($transaction && $transaction->has('Exchange')) {
                $exchange = $transaction->find('Exchange');
                $data['Permuta'] = count($exchange) > 0 ? intval($exchange[0]->text()) : 0;
            }

            $condominium = $imovel->find('Condominium');
            if (count($condominium)) {
                $condominium = $condominium[0];
            }

            $data['Andares'] = NULL;
            if ($condominium && $condominium->has('Floors')) {
                $floors = $condominium->find('Floors');
                $data['Andares'] = count($floors) > 0 ? $floors[0]->text() : NULL;
            }

            $data['UnidadesAndar'] = NULL;
            if ($condominium && $condominium->has('UnitsPerFloor')) {
                $unitsPerFloor = $condominium->find('UnitsPerFloor');
                $data['UnidadesAndar'] = count($unitsPerFloor) > 0 ? $unitsPerFloor[0]->text() : NULL;
            }

            $data['Torres'] = NULL;
            if ($condominium && $condominium->has('Towers')) {
                $towers = $condominium->find('Towers');
                $data['Torres'] = count($towers) > 0 ? $towers[0]->text() : NULL;
            }

            $data['Construtora'] = 0;
            if ($condominium && $condominium->has('Builder')) {
                $builder = $condominium->find('Builder');
                $data['Construtora'] = count($builder) > 0 ? $builder[0]->text() : 0;
            }

            $data['MostrarEndereco'] = 2;
            $data['AreaTotal'] = NULL;

            $details = $imovel->find('Details');
            $details = count($details) > 0 ? $details[0] : null;
            $propertyType = $details ? $details->find('PropertyType') : [];
            $data['TipoImovel'] = $details && count($propertyType) > 0 ? $propertyType[0]->text() : '';
            $data['NomeImovel'] = "";
            $data['Novo'] = NULL;
            if ($details && $details->has('NewProperty')) {
                $newProperty = $details->find('NewProperty');
                $data['Novo'] = count($newProperty) > 0 ? $newProperty[0]->text() : NULL;
            }

            $data['AreaUtil'] = 0;
            if ($details && $details->has('LivingArea')) {
                $livingArea = $details->find('LivingArea');
                $data['AreaUtil'] = count($livingArea) > 0 ? $livingArea[0]->text() : 0;
            }
            if (empty($data['AreaUtil'])) {
                $data['AreaUtil'] = 0;
            }

            $data['AreaTerreno'] = 0;
            if ($details && $details->has('LotArea')) {
                $lotArea = $details->find('LotArea');
                $data['AreaTerreno'] = count($lotArea) > 0 ? $lotArea[0]->text() : 0;
            }

            $data['AreaConstruida'] = NULL;

            $data['AnoConstrucao'] = 0;
            if ($details && $details->has('ConstructionYear')) {
                $constructionYear = $details->find('ConstructionYear');
                $data['AnoConstrucao'] = count($constructionYear) > 0 ? $constructionYear[0]->text() : 0;
            }

            $data['QtdDormitorios'] = 0;
            if ($details && $details->has('Bedrooms')) {
                $bedrooms = $details->find('Bedrooms');
                $data['QtdDormitorios'] = count($bedrooms) > 0 ? $bedrooms[0]->text() : 0;
            }

            $data['QtdSuites'] = NULL;
            if ($details && $details->has('Suites')) {
                $suites = $details->find('Suites');
                $data['QtdSuites'] = count($suites) > 0 ? $suites[0]->text() : NULL;
            }

            $data['QtdBanheiros'] = 0;
            if ($details->has('Bathrooms')) {
                $data['QtdBanheiros'] = $details->find('Bathrooms')[0]->text();
            }

            $data['QtdVagas'] = 0;
            if ($details->has('Garage')) {
                $data['QtdVagas'] = $details->find('Garage')[0]->text();
            }

            $data['Features'] = [];
            if ($imovel->has('Features')) {
                foreach ($imovel->find('Features')[0]->children() as $feature) {
                    $featuretStr = $feature->text();
                    if (preg_match('/[A-Za-z]/', $featuretStr) || preg_match('/[0-9]/', $featuretStr)) {
                        $data['Features'][] = $featuretStr;
                    }
                }
            }

            $location = $imovel->find('Location')[0];
            $data['UF'] = NULL;
            if ($location->has('State')) {
                $data['UF'] = $location->find('State')[0]->getAttribute('abbreviation') ?? $location->find('State')[0]->text();
            }

            $data['MostrarEndereco'] = $location->getAttribute('displayAddress');
            $data['Cidade'] = '';
            if ($location->has('City')) {
                $data['Cidade'] = $location->find('City')[0]->text();
            }

            $data['Bairro'] = '';
            if ($location->has('Neighborhood')) {
                $data['Bairro'] = $location->find('Neighborhood')[0]->text();
            }

            $data['BairroComercial'] = NULL;
            $businessDistrict = $location->find('BusinessDistrict');
            if (count($businessDistrict)) {
                $data['BairroComercial'] = $businessDistrict[0]->text();
            }

            $data['CEP'] = 0;

            $cep = $location->find('PostalCode');
            if (count($cep)) {
                $data['CEP'] = $cep[0]->text();
            }

            $data['Endereco'] = '';
            if ($location->has('Address')) {
                $data['Endereco'] = $location->find('Address')[0]->text();
            }

            $data['Numero'] = NULL;
            if ($location->has('StreetNumber')) {
                $data['Numero'] = $location->find('StreetNumber')[0]->text();
            }

            $data['Complemento'] = NULL;
            if ($location->has('Complement')) {
                $data['Complemento'] = $location->find('Complement')[0]->text();
            }

            $data['Latitude'] = NULL;
            if ($location->has('Latitude')) {
                $data['Latitude'] = $location->find('Latitude')[0]->text();
            }

            $data['Longitude'] = NULL;
            if ($location->has('Longitude')) {
                $data['Longitude'] = $location->find('Longitude')[0]->text();
            }

            $data['Video'] = NULL;

            $data['images'] = [];
                $images = $imovel->find('Media');
                $imagesCounter = 0;
                if (count($images)) {
                    foreach ($images[0]->children() as $media) {
                        if (stristr($media->text(), 'http') && $media->getAttribute('medium') == 'video') {
                            $data['Video'] = $media->text();
                            continue;
                        }

                        $data['images'][] = $media->text();
                        ++$imagesCounter;

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
                if ($imovel['LocationWeekly']) {
                    $imovel['PrecoLocacao'] = $imovel['PrecoLocacao'] * 4;
                }
            }

            if ($imovel['PrecoTemporada']) {
                $imovel['PrecoTemporada'] = convertToNumber($imovel['PrecoTemporada']);
            }

            $imovel['TipoOferta'] = $this->parserOfferType($imovel['TipoOferta'], $imovel['PrecoLocacao'], $imovel['PrecoTemporada']);

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

            $imovel['MostrarEndereco'] = $this->parserShowAddress($imovel['MostrarEndereco']);

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

        $dataCollection = collect($this->data);
        $duplicatesEntry = $dataCollection->duplicates('CodigoImovel');
        foreach ($duplicatesEntry as $key => $value) {
            $dataCollection->forget($key);
        }

        if ($duplicatesEntry->count()) {
            $duplicatesIds = implode(" - ", $duplicatesEntry->toArray());
            $this->toLog[] = "Os seguintes imóveis não foram inseridos por duplicidade(Baseado no código do imóvel): {$duplicatesIds}.";
        }
        $this->data = $dataCollection;
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
        $offerType = strtolower(trim(preg_replace('/(\v|\s)+/', ' ', $offerType)));

        if (str_contains($offerType, 'sell') || str_contains($offerType, 'sale') || str_contains($offerType, 'venda')) {
            return 1;
        } elseif (str_contains($offerType, 'season') || str_contains($offerType, 'temporada')) {
            return 4;
        } elseif (str_contains($offerType, 'rent') || str_contains($offerType, 'aluguel') || str_contains($offerType, 'locação') || str_contains($offerType, 'locacao') || str_contains($offerType, 'alugar')) {

            if ($precoLocacao > 0 && $precoTemporada > 0) {
                return 7;
            } elseif($precoTemporada > 0) {
                return 4;
            } else {
                return 2;
            }
        } elseif ((str_contains($offerType, 'sell') || str_contains($offerType, 'sale') || str_contains($offerType, 'venda')) && (str_contains($offerType, 'rent') || str_contains($offerType, 'aluguel') || str_contains($offerType, 'locação') || str_contains($offerType, 'locacao') || str_contains($offerType, 'alugar'))) {

            if ($precoLocacao > 0 && $precoTemporada > 0) {
                return 5;
            } elseif($precoTemporada > 0) {
                return 6;
            } else {
                return 3;
            }
        } elseif ((str_contains($offerType, 'sell') || str_contains($offerType, 'sale') || str_contains($offerType, 'venda')) && (str_contains($offerType, 'rent') || str_contains($offerType, 'aluguel') || str_contains($offerType, 'locação') || str_contains($offerType, 'locacao') || str_contains($offerType, 'alugar')) && (str_contains($offerType, 'season') || str_contains($offerType, 'temporada'))) {
            return 5;
        } elseif ((str_contains($offerType, 'sell') || str_contains($offerType, 'sale') || str_contains($offerType, 'venda')) && (str_contains($offerType, 'season') || str_contains($offerType, 'temporada'))) {
            return 6;
        } elseif ((str_contains($offerType, 'rent') || str_contains($offerType, 'aluguel') || str_contains($offerType, 'locação') || str_contains($offerType, 'locacao') || str_contains($offerType, 'alugar')) && (str_contains($offerType, 'season') || str_contains($offerType, 'temporada'))) {
            return 7;
        } else {
            $this->toLog[] = "TipoOferta não identificada, o imóvel não foi inserido. Tipo de Oferta no XML: \"$offerType\" - trimed(com regex): \"$offerType\" - CodigoImovel(no XML) do Imóvel: {$this->imovelCode}.";
            return -1;
        }
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
            case 'em construção':
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
        foreach ($features as $feature) {
            $featureParsed = unicode_conversor($feature);
            $findImovelFeature = ImovelFeatures::whereJsonContains('keywords', strtolower($featureParsed))->first();
            if (!$findImovelFeature) {
                $this->featuresNFound[] = strtolower($featureParsed);
            } else {
                $parsedFeatures[] = $findImovelFeature->feature_id;
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

    protected function parserShowAddress(Mixed $displayType) : Int {
        if (gettype($displayType) == 'integer') {
            return $displayType;
        }

        $lowerStr = strtolower($displayType);
        if (str_contains($lowerStr, 'all')) {
            return 2;
        } elseif (str_contains($lowerStr, 'street')) {
            return 1;
        } elseif (str_contains($lowerStr, 'neighborhood')) {
            return 0;
        }

        return 2;
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
                Log::channel('integration')->info('Imóvel ignorado por oferta inválida', [
                    'integration_id' => $this->integration->id,
                    'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                    'tipo_oferta' => $imovel['TipoOferta'],
                    'negotiation_id' => $imovel['NegotiationId']
                ]);
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

            Log::channel('integration_items')->info('Imóvel processado', [
                'integration_id' => $this->integration->id,
                'user_id' => $user_id,
                'anuncio_id' => $imovelId,
                'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                'is_new' => $isNewAnuncio,
                'negotiation_id' => $imovel['NegotiationId'],
                'tipo_imovel' => $imovel['TipoImovel']
            ]);

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
                                    // Log antes do upload S3
                                    \Log::channel('integration')->info("📤 S3: Starting image upload", [
                                        'integration_id' => $this->integration->id,
                                        'imovel_id' => $imovelId,
                                        'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                        'image_url' => $url,
                                        's3_path' => $s3Path,
                                        'image_size_bytes' => strlen($originalData),
                                        'image_dimensions' => [
                                            'width' => $imageObject->width(),
                                            'height' => $imageObject->height()
                                        ]
                                    ]);
                                    
                                    $uploadStartTime = microtime(true);
                                    Storage::disk('do_spaces')->put($s3Path, $originalData, 'public');
                                    $uploadTime = microtime(true) - $uploadStartTime;
                                    
                                    // Log após upload S3 bem-sucedido
                                    \Log::channel('integration')->info("✅ S3: Image upload successful", [
                                        'integration_id' => $this->integration->id,
                                        'imovel_id' => $imovelId,
                                        'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                        's3_path' => $s3Path,
                                        'upload_time_seconds' => round($uploadTime, 3),
                                        'upload_speed_mbps' => round((strlen($originalData) / 1024 / 1024) / $uploadTime, 2)
                                    ]);

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
                            Log::channel('integration_items')->info('Imagens inseridas para imóvel', [
                                'integration_id' => $this->integration->id,
                                'anuncio_id' => $imovelId,
                                'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                'images_count' => $imagesCounter
                            ]);
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
                                    // Log antes do upload S3
                                    \Log::channel('integration')->info("📤 S3: Starting image upload", [
                                        'integration_id' => $this->integration->id,
                                        'imovel_id' => $imovelId,
                                        'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                        'image_url' => $url,
                                        's3_path' => $s3Path,
                                        'image_size_bytes' => strlen($originalData),
                                        'image_dimensions' => [
                                            'width' => $imageObject->width(),
                                            'height' => $imageObject->height()
                                        ]
                                    ]);
                                    
                                    $uploadStartTime = microtime(true);
                                    Storage::disk('do_spaces')->put($s3Path, $originalData, 'public');
                                    $uploadTime = microtime(true) - $uploadStartTime;
                                    
                                    // Log após upload S3 bem-sucedido
                                    \Log::channel('integration')->info("✅ S3: Image upload successful", [
                                        'integration_id' => $this->integration->id,
                                        'imovel_id' => $imovelId,
                                        'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                        's3_path' => $s3Path,
                                        'upload_time_seconds' => round($uploadTime, 3),
                                        'upload_speed_mbps' => round((strlen($originalData) / 1024 / 1024) / $uploadTime, 2)
                                    ]);

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
                            Log::channel('integration_items')->info('Imagens atualizadas para imóvel', [
                                'integration_id' => $this->integration->id,
                                'anuncio_id' => $imovelId,
                                'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                'images_count' => $imagesCounter
                            ]);
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

        $integrationInfo = [
            'system' => 'Imovel Guide',
            'status' => 2,
            'qtd' => $this->imoveisCount,
            'updated_at' => Carbon::now()->toDateTimeString(),
            'last_integration' => Carbon::now()->toDateTimeString()
        ];

        $this->integration->update($integrationInfo);
        if ($this->canUpdateIntegrationStatus()) {
            $this->endIntegration();
        } else {
            $this->endIntegrationWithErrorStatus();
        }

        $this->removeOldData($this->data);

        $this->setParsed(true);
    }
}