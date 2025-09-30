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

class EnglishGlobalModel extends XMLBaseParser {
    public function __construct(Document $xml, Integracao $integration) {
        parent::__construct($xml, $integration);
        $this->startIntegration();
    }

    protected function parserXml() : Void {
        try {
            $imoveis = $this->getXml()->find('listings > listing');
            if (empty($imoveis)) {
                $imoveis = $this->getXml()->find('listing');
            }
            if (empty($imoveis)) {
                // Tentar com namespace
                $imoveis = $this->getXml()->find('*|listings > *|listing');
            }
            if (empty($imoveis)) {
                // Última tentativa: buscar todos os elementos listing
                $imoveis = $this->getXml()->find('*|listing');
            }
            $this->imoveisCount = count($imoveis);
            $rootElements = $this->getXml()->find('*');

            foreach ($imoveis as $index => $imovel) {
            $data = [];
            // Buscar campos usando XPath para XMLs com namespace
            $dom = $this->getXml()->getDocument();
            $xpath = new \DOMXPath($dom);
            
            // Registrar namespace se existir
            $rootElement = $dom->documentElement;
            if ($rootElement && $rootElement->hasAttribute('xmlns')) {
                $namespace = $rootElement->getAttribute('xmlns');
                $xpath->registerNamespace('ns', $namespace);
                $nsPrefix = 'ns:';
            } else {
                $nsPrefix = '';
            }
            
            // Verificar se o XML tem elementos Listing (com namespace)
            $listingNodes = [];
            if ($nsPrefix === 'ns:') {
                $listingNodes = $xpath->query('//ns:Listing');
            }
            
            if (count($listingNodes) === 0) {
                // Se não encontrou com namespace, tentar sem namespace
                $listingNodes = $xpath->query('//Listing');
                if (count($listingNodes) === 0) {
                    // Se ainda não encontrou, pode ser outro formato
                    // Verificar se tem elementos Property, Imovel, etc.
                    $propertyNodes = $xpath->query('//Property');
                    $imovelNodes = $xpath->query('//Imovel');
                    $imovelNodes2 = $xpath->query('//imovel');
                    
                    if (count($propertyNodes) > 0) {
                        // Formato Imovel Guide - usar Property
                        $nsPrefix = '';
                        $listingNodes = $propertyNodes;
                    } elseif (count($imovelNodes) > 0) {
                        // Formato Vista - usar Imovel
                        $nsPrefix = '';
                        $listingNodes = $imovelNodes;
                    } elseif (count($imovelNodes2) > 0) {
                        // Formato ImobiBrasil - usar imovel
                        $nsPrefix = '';
                        $listingNodes = $imovelNodes2;
                    }
                } else {
                    // Encontrou sem namespace
                    $nsPrefix = '';
                }
            }
            
            // Encontrar o nó DOM correto no documento atual
            $imovelNode = null;
            if (count($listingNodes) > 0) {
                // Encontrar o índice do elemento atual
                $currentIndex = 0;
                // Tentar diferentes seletores baseado no tipo de elemento encontrado
                $selector = '*|listing';
                if (count($this->getXml()->find('*|property')) > 0) {
                    $selector = '*|property';
                } elseif (count($this->getXml()->find('*|imovel')) > 0) {
                    $selector = '*|imovel';
                } elseif (count($this->getXml()->find('*|Imovel')) > 0) {
                    $selector = '*|Imovel';
                }
                
                foreach ($this->getXml()->find($selector) as $index => $listing) {
                    if ($listing === $imovel) {
                        $currentIndex = $index;
                        break;
                    }
                }
                if ($currentIndex < count($listingNodes)) {
                    $imovelNode = $listingNodes->item($currentIndex);
                }
            }
            
            if (!$imovelNode) {
                // Fallback: usar o nó do DiDom (pode causar erro, mas vamos tentar)
                $imovelNode = $imovel->getNode();
            }
            
            // Buscar campos usando XPath com diferentes formatos
            $listingIdNodes = null;
            if ($nsPrefix === 'ns:') {
                $listingIdNodes = $xpath->query("ns:ListingID", $imovelNode);
            }
            if (!$listingIdNodes || $listingIdNodes->length === 0) {
                $listingIdNodes = $xpath->query("ListingID", $imovelNode);
                if ($listingIdNodes->length === 0) {
                    $listingIdNodes = $xpath->query("PropertyCode", $imovelNode);
                    if ($listingIdNodes->length === 0) {
                        $listingIdNodes = $xpath->query("CodigoImovel", $imovelNode);
                        if ($listingIdNodes->length === 0) {
                            $listingIdNodes = $xpath->query("ref", $imovelNode);
                        }
                    }
                }
            }
            $data['CodigoImovel'] = $listingIdNodes && $listingIdNodes->length > 0 ? $listingIdNodes->item(0)->textContent : '';

            $statusNodes = null;
            if ($nsPrefix === 'ns:') {
                $statusNodes = $xpath->query("ns:Status", $imovelNode);
            }
            if (!$statusNodes || $statusNodes->length === 0) {
                $statusNodes = $xpath->query("Status", $imovelNode);
                if ($statusNodes->length === 0) {
                    $statusNodes = $xpath->query("Publicar", $imovelNode);
                }
            }
            $data['Status'] = $statusNodes && $statusNodes->length > 0 ? $statusNodes->item(0)->textContent : 'Ativo';

            $data['Subtitle'] = NULL;
            $titleNodes = null;
            if ($nsPrefix === 'ns:') {
                $titleNodes = $xpath->query("ns:Title", $imovelNode);
            }
            if (!$titleNodes || $titleNodes->length === 0) {
                $titleNodes = $xpath->query("Title", $imovelNode);
                if ($titleNodes->length === 0) {
                    $titleNodes = $xpath->query("TituloImovel", $imovelNode);
                    if ($titleNodes->length === 0) {
                        $titleNodes = $xpath->query("titulo", $imovelNode);
                    }
                }
            }
            if ($titleNodes && $titleNodes->length > 0) {
                $data['Subtitle'] = $titleNodes->item(0)->textContent;
            }

            $data['TipoOferta'] = -1;
            $transactionTypeNodes = null;
            if ($nsPrefix === 'ns:') {
                $transactionTypeNodes = $xpath->query("ns:TransactionType", $imovelNode);
            }
            if (!$transactionTypeNodes || $transactionTypeNodes->length === 0) {
                $transactionTypeNodes = $xpath->query("TransactionType", $imovelNode);
                if ($transactionTypeNodes->length === 0) {
                    $transactionTypeNodes = $xpath->query("transacao", $imovelNode);
                    if ($transactionTypeNodes->length === 0) {
                        // Verificar se tem campos de venda/locação separados
                        $vendaNodes = $xpath->query("Venda", $imovelNode);
                        $locacaoNodes = $xpath->query("Locacao", $imovelNode);
                        if ($vendaNodes->length > 0 && $locacaoNodes->length > 0) {
                            $venda = strtolower($vendaNodes->item(0)->textContent);
                            $locacao = strtolower($locacaoNodes->item(0)->textContent);
                            if ($venda === 'sim' && $locacao === 'sim') {
                                $data['TipoOferta'] = 'Sale/Rent';
                            } elseif ($venda === 'sim') {
                                $data['TipoOferta'] = 'Sale';
                            } elseif ($locacao === 'sim') {
                                $data['TipoOferta'] = 'Rent';
                            }
                        }
                    }
                }
            }
            if ($transactionTypeNodes && $transactionTypeNodes->length > 0) {
                $data['TipoOferta'] = $transactionTypeNodes->item(0)->textContent;
            }

            // Buscar Details usando XPath
            $detailsNodes = null;
            if ($nsPrefix === 'ns:') {
                $detailsNodes = $xpath->query("ns:Details", $imovelNode);
            }
            if (!$detailsNodes || $detailsNodes->length === 0) {
                $detailsNodes = $xpath->query("Details", $imovelNode);
            }
            $details = $detailsNodes && $detailsNodes->length > 0 ? $detailsNodes->item(0) : null;
            $data['Descricao'] = '';
            if ($details) {
                $descriptionNodes = null;
                if ($nsPrefix === 'ns:') {
                    $descriptionNodes = $xpath->query("ns:Description", $details);
                }
                if (!$descriptionNodes || $descriptionNodes->length === 0) {
                    $descriptionNodes = $xpath->query("Description", $details);
                }
                if ($descriptionNodes && $descriptionNodes->length > 0) {
                    $data['Descricao'] = $descriptionNodes->item(0)->textContent;
                }
            } else {
                // Tentar buscar diretamente no imovel
                $descriptionNodes = null;
                if ($nsPrefix === 'ns:') {
                    $descriptionNodes = $xpath->query("ns:Description", $imovelNode);
                }
                if (!$descriptionNodes || $descriptionNodes->length === 0) {
                    $descriptionNodes = $xpath->query("Description", $imovelNode);
                }
                if ($descriptionNodes && $descriptionNodes->length > 0) {
                    $data['Descricao'] = $descriptionNodes->item(0)->textContent;
                }
            }

            $data['PrecoVenda'] = 0;
            if ($details) {
                $listPriceNodes = null;
                if ($nsPrefix === 'ns:') {
                    $listPriceNodes = $xpath->query("ns:ListPrice", $details);
                }
                if (!$listPriceNodes || $listPriceNodes->length === 0) {
                    $listPriceNodes = $xpath->query("ListPrice", $details);
                }
                if ($listPriceNodes && $listPriceNodes->length > 0) {
                    $data['PrecoVenda'] = $listPriceNodes->item(0)->textContent;
                }
            } else {
                // Tentar buscar diretamente no imovel
                $listPriceNodes = null;
                if ($nsPrefix === 'ns:') {
                    $listPriceNodes = $xpath->query("ns:ListPrice", $imovelNode);
                }
                if (!$listPriceNodes || $listPriceNodes->length === 0) {
                    $listPriceNodes = $xpath->query("ListPrice", $imovelNode);
                    if ($listPriceNodes->length === 0) {
                        $listPriceNodes = $xpath->query("valor", $imovelNode);
                    }
                }
                if ($listPriceNodes && $listPriceNodes->length > 0) {
                    $data['PrecoVenda'] = $listPriceNodes->item(0)->textContent;
                }
            }
            if (empty($data['PrecoVenda'])) {
                $data['PrecoVenda'] = 0;
            }

            $data['PrecoLocacao'] = 0;
            $data['LocationWeekly'] = false;
            if ($details) {
                $rentalPriceNodes = null;
                if ($nsPrefix === 'ns:') {
                    $rentalPriceNodes = $xpath->query("ns:RentalPrice", $details);
                }
                if (!$rentalPriceNodes || $rentalPriceNodes->length === 0) {
                    $rentalPriceNodes = $xpath->query("RentalPrice", $details);
                }
                $rentalPrice = $rentalPriceNodes && $rentalPriceNodes->length > 0 ? $rentalPriceNodes->item(0) : null;
                if ($rentalPrice && $rentalPrice->getAttribute('currency') && $rentalPrice->getAttribute('currency') == "BRL") {
                    $data['PrecoLocacao'] = $rentalPrice->textContent;
                }

                if ($rentalPrice) {
                    $locationWeekly = $rentalPrice->getAttribute('period');
                    if ($locationWeekly && strtolower($locationWeekly) == "weekly") {
                        $data['LocationWeekly'] = true;
                    }
                }
            } else {
                // Tentar buscar diretamente no imovel
                $rentalPriceNodes = null;
                if ($nsPrefix === 'ns:') {
                    $rentalPriceNodes = $xpath->query("ns:RentalPrice", $imovelNode);
                }
                if (!$rentalPriceNodes || $rentalPriceNodes->length === 0) {
                    $rentalPriceNodes = $xpath->query("RentalPrice", $imovelNode);
                    if ($rentalPriceNodes->length === 0) {
                        $rentalPriceNodes = $xpath->query("valor_locacao", $imovelNode);
                    }
                }
                if ($rentalPriceNodes && $rentalPriceNodes->length > 0) {
                    $data['PrecoLocacao'] = $rentalPriceNodes->item(0)->textContent;
                }
            }

            $data['PrecoTemporada'] = NULL;
            if ($details) {
                $rentalPriceNodes = null;
                if ($nsPrefix === 'ns:') {
                    $rentalPriceNodes = $xpath->query("ns:RentalPrice", $details);
                }
                if (!$rentalPriceNodes || $rentalPriceNodes->length === 0) {
                    $rentalPriceNodes = $xpath->query("RentalPrice", $details);
                }
                if ($rentalPriceNodes && $rentalPriceNodes->length > 0) {
                    $rentalPrice = $rentalPriceNodes->item(0);
                    if ($rentalPrice && $rentalPrice->getAttribute('period') && strtolower($rentalPrice->getAttribute('period')) == "daily") {
                        $data['PrecoTemporada'] = $rentalPrice->textContent;
                    }
                }
            }

            $data['Spotlight'] = 0;
            $data['Highlighted'] = NULL;
            $publicationTypeNodes = null;
            if ($nsPrefix === 'ns:') {
                $publicationTypeNodes = $xpath->query("ns:PublicationType", $imovelNode);
            }
            if (!$publicationTypeNodes || $publicationTypeNodes->length === 0) {
                $publicationTypeNodes = $xpath->query("PublicationType", $imovelNode);
            }
            if ($publicationTypeNodes && $publicationTypeNodes->length > 0) {
                $publicationType = $publicationTypeNodes->item(0)->textContent;
                $data['Highlighted'] = in_array($publicationType, ['PREMIUM', 'SUPER_PREMIUM', 'PREMIUM_1', 'PREMIUM_2']) ? 1 : 0;
            }

            $data['GarantiaAluguel'] = 0;

            $data['ValorIPTU'] = NULL;
            if ($details) {
                $yearlyTaxNodes = null;
                if ($nsPrefix === 'ns:') {
                    $yearlyTaxNodes = $xpath->query("ns:YearlyTax", $details);
                }
                if (!$yearlyTaxNodes || $yearlyTaxNodes->length === 0) {
                    $yearlyTaxNodes = $xpath->query("YearlyTax", $details);
                }
                if ($yearlyTaxNodes && $yearlyTaxNodes->length > 0) {
                    $data['ValorIPTU'] = $yearlyTaxNodes->item(0)->textContent;
                }
            }

            $data['PrecoCondominio'] = NULL;
            if ($details) {
                $propertyAdminFeeNodes = null;
                if ($nsPrefix === 'ns:') {
                    $propertyAdminFeeNodes = $xpath->query("ns:PropertyAdministrationFee", $details);
                }
                if (!$propertyAdminFeeNodes || $propertyAdminFeeNodes->length === 0) {
                    $propertyAdminFeeNodes = $xpath->query("PropertyAdministrationFee", $details);
                }
                if ($propertyAdminFeeNodes && $propertyAdminFeeNodes->length > 0) {
                    $data['PrecoCondominio'] = $propertyAdminFeeNodes->item(0)->textContent;
                }
            }

            $data['Permuta'] = 0;

            $data['Andares'] = NULL;
            if ($details) {
                $floorsNodes = null;
                if ($nsPrefix === 'ns:') {
                    $floorsNodes = $xpath->query("ns:Floors", $details);
                }
                if (!$floorsNodes || $floorsNodes->length === 0) {
                    $floorsNodes = $xpath->query("Floors", $details);
                }
                if ($floorsNodes && $floorsNodes->length > 0) {
                    $data['Andares'] = $floorsNodes->item(0)->textContent;
                }
            }

            $data['UnidadesAndar'] = NULL;
            if ($details) {
                $unitsPerFloorNodes = null;
                if ($nsPrefix === 'ns:') {
                    $unitsPerFloorNodes = $xpath->query("ns:UnitsPerFloor", $details);
                }
                if (!$unitsPerFloorNodes || $unitsPerFloorNodes->length === 0) {
                    $unitsPerFloorNodes = $xpath->query("UnitsPerFloor", $details);
                }
                if ($unitsPerFloorNodes && $unitsPerFloorNodes->length > 0) {
                    $data['UnidadesAndar'] = $unitsPerFloorNodes->item(0)->textContent;
                }
            }

            $data['Torres'] = NULL;
            $data['Construtora'] = 0;

            $data['MostrarEndereco'] = 2;
            $data['AreaTotal'] = NULL;

            $data['TipoImovel'] = 'outros';
            $propertyTypeNodes = null;
            if ($nsPrefix === 'ns:') {
                $propertyTypeNodes = $xpath->query("ns:PropertyType", $imovelNode);
            }
            if (!$propertyTypeNodes || $propertyTypeNodes->length === 0) {
                $propertyTypeNodes = $xpath->query("PropertyType", $imovelNode);
            }
            if ($propertyTypeNodes && $propertyTypeNodes->length > 0) {
                $data['TipoImovel'] = $propertyTypeNodes->item(0)->textContent;
            }

            $data['NomeImovel'] = "";
            $data['Novo'] = NULL;

            $data['AreaUtil'] = 0;
            if ($details) {
                $livingAreaNodes = null;
                if ($nsPrefix === 'ns:') {
                    $livingAreaNodes = $xpath->query("ns:LivingArea", $details);
                }
                if (!$livingAreaNodes || $livingAreaNodes->length === 0) {
                    $livingAreaNodes = $xpath->query("LivingArea", $details);
                }
                if ($livingAreaNodes && $livingAreaNodes->length > 0) {
                    $data['AreaUtil'] = $livingAreaNodes->item(0)->textContent;
                }
            }
            if (empty($data['AreaUtil'])) {
                $data['AreaUtil'] = 0;
            }

            $data['AreaTerreno'] = 0;
            if ($details) {
                $lotAreaNodes = null;
                if ($nsPrefix === 'ns:') {
                    $lotAreaNodes = $xpath->query("ns:LotArea", $details);
                }
                if (!$lotAreaNodes || $lotAreaNodes->length === 0) {
                    $lotAreaNodes = $xpath->query("LotArea", $details);
                }
                if ($lotAreaNodes && $lotAreaNodes->length > 0) {
                    $data['AreaTerreno'] = $lotAreaNodes->item(0)->textContent;
                }
            }

            $data['AreaConstruida'] = NULL;
            if ($details) {
                $constructedAreaNodes = null;
                if ($nsPrefix === 'ns:') {
                    $constructedAreaNodes = $xpath->query("ns:ConstructedArea", $details);
                }
                if (!$constructedAreaNodes || $constructedAreaNodes->length === 0) {
                    $constructedAreaNodes = $xpath->query("ConstructedArea", $details);
                }
                if ($constructedAreaNodes && $constructedAreaNodes->length > 0) {
                    $data['AreaConstruida'] = $constructedAreaNodes->item(0)->textContent;
                }
            }

            $data['AnoConstrucao'] = 0;
            if ($details) {
                $constructionYearNodes = null;
                if ($nsPrefix === 'ns:') {
                    $constructionYearNodes = $xpath->query("ns:ConstructionYear", $details);
                }
                if (!$constructionYearNodes || $constructionYearNodes->length === 0) {
                    $constructionYearNodes = $xpath->query("ConstructionYear", $details);
                }
                if ($constructionYearNodes && $constructionYearNodes->length > 0) {
                    $data['AnoConstrucao'] = $constructionYearNodes->item(0)->textContent;
                }
            }

            $data['QtdDormitorios'] = 0;
            if ($details) {
                $bedroomsNodes = null;
                if ($nsPrefix === 'ns:') {
                    $bedroomsNodes = $xpath->query("ns:Bedrooms", $details);
                }
                if (!$bedroomsNodes || $bedroomsNodes->length === 0) {
                    $bedroomsNodes = $xpath->query("Bedrooms", $details);
                }
                if ($bedroomsNodes && $bedroomsNodes->length > 0) {
                    $data['QtdDormitorios'] = $bedroomsNodes->item(0)->textContent;
                }
            }

            $data['QtdSuites'] = NULL;
            if ($details) {
                $suitesNodes = null;
                if ($nsPrefix === 'ns:') {
                    $suitesNodes = $xpath->query("ns:Suites", $details);
                }
                if (!$suitesNodes || $suitesNodes->length === 0) {
                    $suitesNodes = $xpath->query("Suites", $details);
                }
                if ($suitesNodes && $suitesNodes->length > 0) {
                    $data['QtdSuites'] = $suitesNodes->item(0)->textContent;
                }
            }

            $data['QtdBanheiros'] = 0;
            if ($details) {
                $bathroomsNodes = null;
                if ($nsPrefix === 'ns:') {
                    $bathroomsNodes = $xpath->query("ns:Bathrooms", $details);
                }
                if (!$bathroomsNodes || $bathroomsNodes->length === 0) {
                    $bathroomsNodes = $xpath->query("Bathrooms", $details);
                }
                if ($bathroomsNodes && $bathroomsNodes->length > 0) {
                    $data['QtdBanheiros'] = $bathroomsNodes->item(0)->textContent;
                }
            }

            $data['QtdVagas'] = 0;
            if ($details) {
                $garageNodes = null;
                if ($nsPrefix === 'ns:') {
                    $garageNodes = $xpath->query("ns:Garage", $details);
                }
                if (!$garageNodes || $garageNodes->length === 0) {
                    $garageNodes = $xpath->query("Garage", $details);
                }
                if ($garageNodes && $garageNodes->length > 0) {
                    $data['QtdVagas'] = $garageNodes->item(0)->textContent;
                }
            }

            $data['Features'] = [];
            if (count($imovel->find('features')) > 0) {
                $featuresArray = $imovel->find('features');
                $featuresElement = count($featuresArray) > 0 ? $featuresArray[0] : null;
                $features = $featuresElement->find('*');
                foreach ($features as $feature) {
                    $featuretStr = $feature->text();
                    if (preg_match('/[A-Za-z]/', $featuretStr) || preg_match('/[0-9]/', $featuretStr)) {
                        $data['Features'][] = $featuretStr;
                    }
                }
            }

            $location = $imovel->find('location');
            if (count($location) > 1) {
                if (strtolower($location[1]->parent()->tagName()) == "listing") {
                    $location = $location[1];
                } else {
                    $location = $location[0];
                }
            } else {
                $location = count($location) > 0 ? $location[0] : null;
            }

            $data['MostrarEndereco'] = $location ? $location->getAttribute('displayAddress') : '';

            $data['UF'] = '';
            if ($location && count($location->find('state')) > 0) {
                $stateArray = $location->find('state');
                $data['UF'] = count($stateArray) > 0 ? ($stateArray[0]->getAttribute('abbreviation') ?? $stateArray[0]->text()) : '';
            }

            $data['Cidade'] = '';
            if ($location && count($location->find('city')) > 0) {
                $cityArray = $location->find('city');
                $data['Cidade'] = count($cityArray) > 0 ? $cityArray[0]->text() : '';
            }

            $data['Bairro'] = '';
            if ($location && count($location->find('neighborhood')) > 0) {
                $neighborhoodArray = $location->find('neighborhood');
                $data['Bairro'] = count($neighborhoodArray) > 0 ? $neighborhoodArray[0]->text() : '';
            }

            $data['BairroComercial'] = NULL;
            if ($location) {
                $businessDistrict = $location->find('businessdistrict');
            if (count($businessDistrict)) {
                    $data['BairroComercial'] = count($businessDistrict) > 0 ? $businessDistrict[0]->text() : '';
                }
            }

            $data['CEP'] = 0;

            if ($location) {
                $cep = $location->find('postalcode');
            if (count($cep)) {
                    $data['CEP'] = count($cep) > 0 ? $cep[0]->text() : '';
                }
            }

            $data['Endereco'] = '';
            if ($location && count($location->find('address')) > 0) {
                $addressArray = $location->find('address');
                $data['Endereco'] = count($addressArray) > 0 ? $addressArray[0]->text() : '';
            }

            $data['Numero'] = NULL;
            if ($location && count($location->find('streetnumber')) > 0) {
                $streetNumberArray = $location->find('streetnumber');
                $data['Numero'] = count($streetNumberArray) > 0 ? $streetNumberArray[0]->text() : '';
            }

            $data['Complemento'] = NULL;
            if ($location && count($location->find('complement')) > 0) {
                $complementArray = $location->find('complement');
                $data['Complemento'] = count($complementArray) > 0 ? $complementArray[0]->text() : '';
            }

            $data['Latitude'] = NULL;
            if ($location && count($location->find('latitude')) > 0) {
                $latitudeArray = $location->find('latitude');
                $data['Latitude'] = count($latitudeArray) > 0 ? $latitudeArray[0]->text() : '';
            }

            $data['Longitude'] = NULL;
            if ($location && count($location->find('longitude')) > 0) {
                $longitudeArray = $location->find('longitude');
                $data['Longitude'] = count($longitudeArray) > 0 ? $longitudeArray[0]->text() : '';
            }

            $data['Video'] = NULL;

            $data['images'] = [];
                $images = $imovel->find('media');
                $imagesCounter = 0;
                if (count($images)) {
                    $mediaElements = count($images) > 0 ? $images[0]->find('*') : [];
                    foreach ($mediaElements as $media) {
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

            // Filtrar imóveis inativos - REMOVIDO para processar todos os imóveis
            // if (strtolower($data['Status']) === 'inativo') {
            //     Log::channel('integration')->info('Imóvel ignorado por status inativo', [
            //         'integration_id' => $this->integration->id,
            //         'codigo_imovel' => $data['CodigoImovel'] ?? null,
            //         'status' => $data['Status']
            //     ]);
            //     continue;
            // }

            $this->data[$index] = $data;
        }
        } catch (\Exception $e) {
            \Log::error('Erro no parser XML EnglishGlobalModel', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception("EnglishGlobalModel XML parsing failed: " . $e->getMessage(), 0, $e);
        }
    }



    protected function prepareXmlData() : Void {
        try {
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
        } catch (\Exception $e) {
            \Log::error('Erro ao preparar dados XML EnglishGlobalModel', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception("EnglishGlobalModel data preparation failed: " . $e->getMessage(), 0, $e);
        }
    }

    protected function parserImovelType(String $imovelType) : Array {
        $parsedImovelType = unicode_conversor($imovelType);
        $parsedImovelType = strtolower($parsedImovelType);
        $findImovelType = ImovelType::whereJsonContains('keywords', $parsedImovelType)->first();
        if (!$findImovelType) {
            $findImovelType = ImovelType::where('id', $this->getDefaultTypeId())->first();

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
        try {
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
                if ($existingImovel->status === 'inativado') {
                    continue;
                }
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
                                    // CRÍTICO: Log detalhado e incrementar contador de falhas
                                    $this->toLog[] = "Exception na hora do update da imagem - Error: \"". $e->getMessage() . "\" - Imóvel ID(DB): \"$imovelId\" - URL(no XML depois de ser parseado): \"$url\" - CodigoImovel(no XML) do Imóvel: \"{$imovel['CodigoImovel']}\".";

                                    \Log::channel('integration')->error("❌ S3: Image upload failed", [
                                        'integration_id' => $this->integration->id,
                                        'imovel_id' => $imovelId,
                                        'codigo_imovel' => $imovel['CodigoImovel'] ?? null,
                                        'image_url' => $url,
                                        's3_path' => $s3Path ?? 'unknown',
                                        'error' => $e->getMessage(),
                                        'error_type' => get_class($e),
                                        'error_file' => $e->getFile(),
                                        'error_line' => $e->getLine(),
                                        'memory_usage' => memory_get_usage(true)
                                    ]);
                                    
                                    // Incrementar contador de falhas de imagem
                                    if (!isset($this->imageFailures)) {
                                        $this->imageFailures = 0;
                                    }
                                    $this->imageFailures++;
                                }
                            }
                        }

                        if ($imagesCounter) {
                            $this->imagesInserted += $this->insertOrUpdateImages($imovelId, $imagesToInsert, 'updated');
                          
                            if ($this->isManual) {
                                echo "Imagem(update) Nº: $index - Anúncio Código: {$imovel['CodigoImovel']}.\n";
                            }
                        }

                        // CRÍTICO: Validar se houve muitas falhas de imagem
                        if (isset($this->imageFailures) && $this->imageFailures > 10) {
                            \Log::channel('integration')->error("🖼️ IMAGE: Too many image failures detected", [
                                'integration_id' => $this->integration->id,
                                'total_image_failures' => $this->imageFailures,
                                'imovel_id' => $imovelId,
                                'codigo_imovel' => $imovel['CodigoImovel'] ?? null
                            ]);
                            
                            // Adicionar ao log de integração
                            $this->toLog[] = "ALERTA: Muitas falhas de imagem detectadas ({$this->imageFailures}). Verifique conectividade e URLs das imagens.";
                        }
                    }
                }
            }
        }
        $anuncioService = new AnuncioService;
        $anuncioService->validateAdPoints($user_id);

        $this->logDone();
        $this->finalizeIntegration('Ingles(Similar-IG)', $this->data);
        } catch (\Exception $e) {
            \Log::error('Erro ao inserir dados XML EnglishGlobalModel', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception("EnglishGlobalModel data insertion failed: " . $e->getMessage(), 0, $e);
        }
    }
}