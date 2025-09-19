<?php

namespace App\Integracao\Infrastructure\Parsers\Models;

// Support.
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use DiDom\Document;
use Carbon\Carbon;
use Storage;
use Image;

// Models.
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

// Services.
use App\Services\InviteService;

// Modelo property é da Union Softwares.
class UnionModel extends XMLBaseParser {
    public function __construct(Document $xml, Integracao $integration) {
        parent::__construct($xml, $integration);
        $this->startIntegration();
    }

    // Método abstrato que toda classe de XML terá.
    // Função: Extrair dados de cada imóvel para ser inserido no banco de dados.
    protected function parserXml() : Void {
        $imoveis = $this->getXml()->find('Imovel');
        $this->imoveisCount = count($imoveis);

        foreach ($imoveis as $index => $imovel) {
            /*
            O index em $data é a própria coluna no banco de dados,
            e comentado
            $this->data['coluna'] = $imovel->child(0)->text();
            */
            $data = [];
            $data['CodigoImovel'] = $imovel->find('Codigoimovel')[0]->text(); // Attr: <Codigoimovel>.
            $data['Subtitle'] = NULL;
            $subTitle = $imovel->find('Titulo'); // Attr: <Titulo>.
            if (count($subTitle)) {
                $data['Subtitle'] = $subTitle[0]->text();
            }

            $data['Descricao'] = '';
            if ($imovel->has('Anuncioparainternet')) {
                $data['Descricao'] = $imovel->find('Anuncioparainternet')[0]->text(); // Attr: <Anuncioparainternet>.
            }

            // Season/Rent/Sale
            $season = $imovel->find('Temporada');
            $rent = $imovel->find('Locacao');
            $sale = $imovel->find('Venda');

            $data['TipoOferta'] = $this->getOfferType($season, $rent, $sale); // Attr(Child): <Temporada/Locacao/Venda>.

            $data['PrecoVenda'] = 0; // Attr(Child): <Valorvenda>. Default.
            if ($imovel->has('Valorvenda')) {
                $data['PrecoVenda'] = $imovel->find('Valorvenda')[0]->text();
                if ($data['PrecoVenda'] == '') {
                    $data['PrecoVenda'] = 0;
                }
            }
            if (empty($data['PrecoVenda'])) {
                $data['PrecoVenda'] = 0;
            }

            $data['PrecoLocacao'] = 0; // Attr(Child): <Valorlocacao>. Default.
            if ($imovel->has('Valorlocacao')) {
                $data['PrecoLocacao'] = $imovel->find('Valorlocacao')[0]->text();
                if ($data['PrecoLocacao'] == '') {
                    $data['PrecoLocacao'] = 0;
                }
            }

            $data['PrecoTemporada'] = NULL; // Attr(Child): <Valortemporada>. Default.
            if ($imovel->has('Valortemporada')) {
                $data['PrecoTemporada'] = $imovel->find('Valortemporada')[0]->text();
                if ($data['PrecoTemporada'] == '') {
                    $data['PrecoTemporada'] = NULL;
                }
            }

            $data['Spotlight'] = 0; // Attr(Child): <Destaque>.
            $data['Highlighted'] = NULL; // Attr(Child): <Destaque>.
            if ($imovel->has('Destaque')) {
                $data['Highlighted'] = intval($imovel->find('Destaque')[0]->text());
            }

            $data['GarantiaAluguel'] = NULL; // Attr(Child): <RentalGuarantee>.
            /*if ($imovel->has('RentalGuarantee')) {
                $data['GarantiaAluguel'] = $imovel->find('RentalGuarantee')[0]->text();
            }*/
            
            $data['ValorIPTU'] = NULL; // Attr(Child): <Valoriptu>.
            if ($imovel->has('Valoriptu')) {
                $data['ValorIPTU'] = $imovel->find('Valoriptu')[0]->text();
            }
            
            $data['PrecoCondominio'] = NULL; // Attr(Child): <Valorcondominio>.
            if ($imovel->has('Valorcondominio')) {
                $data['PrecoCondominio'] = $imovel->find('Valorcondominio')[0]->text();
                if ($data['PrecoCondominio'] == '') {
                    $data['PrecoCondominio'] = NULL;
                }
            }

            $data['Permuta'] = 0; // Attr(Child): <Permuta>.
            if ($imovel->has('Permuta')) {
                $data['Permuta'] = intval($imovel->find('Permuta')[0]->text());
            }

            $data['Andares'] = NULL; // Attr(Child): <Andares>.
            if ($imovel->has('Andares')) {
                $data['Andares'] = $imovel->find('Andares')[0]->text();
            }

            $data['UnidadesAndar'] = NULL; // Attr(Child): <>.
            /*if ($imovel->has('')) {
                $data['UnidadesAndar'] = $imovel->find('')[0]->text();
            }*/

            $data['Torres'] = NULL; // Attr(Child): <>.
            /*if ($imovel->has('')) {
                $data['Torres'] = $imovel->find('')[0]->text();
            }*/

            $data['Construtora'] = 0; // Attr(Child): <Construtora>.
            if ($imovel->has('Construtora')) {
                $data['Construtora'] = $imovel->find('Construtora')[0]->text();
            }

            $data['MostrarEndereco'] = 2; // Attr(Child): <>.

            $data['AreaTotal'] = NULL; // Attr(Child): <Areatotalsemdeciamal>.
            if ($imovel->has('Areatotalsemdeciamal')) {
                $data['AreaTotal'] = $imovel->find('Areatotalsemdeciamal')[0]->text();
            }
            if (!$data['AreaTotal']) {
                if ($imovel->has('Areatotal')) {
                    $data['AreaTotal'] = $imovel->find('Areatotal')[0]->text();
                }
            }

            $data['TipoImovel'] = $imovel->find('Tipo')[0]->text(); // Attr(Child): <Tipo>.
            $data['NomeImovel'] = "";
            $data['Novo'] = NULL; // Attr(Child): <>.
            /*if ($imovel->has('')) {
                $data['Novo'] = $imovel->find('')[0]->text();
            }*/

            $data['AreaUtil'] = 0; // Attr(Child): <Areautilsemdeciamal>.
            if ($imovel->has('Areautilsemdeciamal')) {
                $data['AreaUtil'] = $imovel->find('Areautilsemdeciamal')[0]->text();
            }
            if (!$data['AreaUtil']) {
                if ($imovel->has('Areautil')) {
                    $data['AreaUtil'] = $imovel->find('Areautil')[0]->text();
                }
            }
            if (empty($data['AreaUtil'])) {
                $data['AreaUtil'] = 0;
            }

            $data['AreaTerreno'] = 0; // Attr(Child): <Areaterrenosemdeciamal>.
            if ($imovel->has('Areaterrenosemdeciamal')) {
                $data['AreaTerreno'] = $imovel->find('Areaterrenosemdeciamal')[0]->text();
            }
            if (!$data['AreaTerreno']) {
                if ($imovel->has('Areaterreno')) {
                    $data['AreaTerreno'] = $imovel->find('Areaterreno')[0]->text();
                }
            }
            if ($data['AreaTerreno'] == '') {
                $data['AreaTerreno'] = 0;
            }

            $data['AreaConstruida'] = NULL; // Attr(Child): <Areacosntruidasemdeciamal>.
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

            $data['AnoConstrucao'] = 0; // Attr(Child): <AnoConstrucao>.
            if ($imovel->has('AnoConstrucao')) {
                $data['AnoConstrucao'] = $imovel->find('AnoConstrucao')[0]->text();
            }

            $data['QtdDormitorios'] = 0; // Attr(Child): <Dormitorios>.
            if ($imovel->has('Dormitorios')) {
                $data['QtdDormitorios'] = $imovel->find('Dormitorios')[0]->text();
            }

            $data['QtdSuites'] = NULL; // Attr(Child): <Suite>.
            if ($imovel->has('Suite')) {
                $data['QtdSuites'] = $imovel->find('Suite')[0]->text();
            }

            $data['QtdBanheiros'] = 0; // Attr(Child): <Banheiro2>.
            if ($imovel->has('Banheiro2')) {
                $data['QtdBanheiros'] = $imovel->find('Banheiro2')[0]->text();
            }

            $data['QtdVagas'] = 0; // Attr(Child): <Garagem>.
            if ($imovel->has('Garagem')) {
                $data['QtdVagas'] = $imovel->find('Garagem')[0]->text();
            }

            $data['Features'] = $this->getFeatures($imovel);

            $data['UF'] = $imovel->find('UnidadeFederativa')[0]->text(); // Attr(Child): <UnidadeFederativa>. - No need Check.
            $data['Cidade'] = '';
            if ($imovel->has('Cidade')) {
                $data['Cidade'] = $imovel->find('Cidade')[0]->text(); // Attr(Child): <Cidade>. - No need Check.
            }

            $data['Bairro'] = $imovel->find('Bairro')[0]->text(); // Attr(Child): <Bairro>. - No need Check.
            $data['BairroComercial'] = NULL;
            $businessDistrict = $imovel->find('Bairrocomercial');
            if (count($businessDistrict)) {
                $data['BairroComercial'] = $businessDistrict[0]->text(); // Attr(Child): <Bairrocomercial>. - No need Check.
            }

            $data['CEP'] = 0;

            $cep = $imovel->find('CEP'); // Attr(Child): <CEP>. - No need Check.
            if (count($cep)) {
                $data['CEP'] = $cep[0]->text();
            }

            $data['Endereco'] = ''; // Attr(Child): <Endereco>.
            if ($imovel->has('Endereco')) {
                $data['Endereco'] = $imovel->find('Endereco')[0]->text();
            }

            $data['Numero'] = NULL; // Attr(Child): <Numero>.
            if ($imovel->has('Numero')) {
                $data['Numero'] = $imovel->find('Numero')[0]->text();
            }

            $data['Complemento'] = NULL; // Attr(Child): <Complemento>.
            if ($imovel->has('Complemento')) {
                $data['Complemento'] = $imovel->find('Complemento')[0]->text();
            }

            $data['Latitude'] = NULL; // Attr(Child): <Latitude>.
            if ($imovel->has('Latitude')) {
                $data['Latitude'] = $imovel->find('Latitude')[0]->text();
            }

            $data['Longitude'] = NULL; // Attr(Child): <Longitude>.
            if ($imovel->has('Longitude')) {
                $data['Longitude'] = $imovel->find('Longitude')[0]->text();
            }

            $data['Video'] = NULL; // Attr(Child): <video>.
            $videos = $imovel->find('Videos');
            if (count($videos)) {
                foreach ($videos[0]->children() as $video) {
                    if ($video->has('LinkVideo')) {
                        $data['Video'] = $video->find('LinkVideo')[0]->text();
                        break;  // Só precisamos de um vídeo.
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

                        if ($imagesCounter == 20) { // Quantidade máxima de imagens.
                            break;
                        }
                    }
                }

            $this->data[$index] = $data;
        }
    }

    // Método abstrato que toda classe de XML terá.
    // Função: Analisa e prepara os dados extraídos que serão inseridos no banco de dados.
    protected function prepareXmlData() : Void {
        foreach ($this->data as $key => $imovel) {
            // Analisando código do imóvel.
            $imovel['CodigoImovel'] = trim($imovel['CodigoImovel']);
            $this->imovelCode = $imovel['CodigoImovel'];

            // Analisando tipo do imóvel.
            $imovelTypeAndName = $this->parserImovelType($imovel['TipoImovel']);
            $imovel['TipoImovel'] = $imovelTypeAndName['TipoImovel'];
            $imovel['NomeImovel'] = $imovelTypeAndName['NomeImovel'];

            // Analisando descrição do imóvel.
            $imovel['Descricao'] = $this->parserDescription($imovel['Descricao']);

            // Analisando subtitle do imóvel pois alguns titulos vem com caracteres especiais e com emojis.
            if ($imovel['Subtitle']) {
                $imovel['Subtitle'] = $this->parserDescription($imovel['Subtitle']);
            }

            // Analisando preço de venda e convertendo-o a inteiro.
            if ($imovel['PrecoVenda']) {
                $imovel['PrecoVenda'] = convertToNumber($imovel['PrecoVenda']);
            }

            // Analisando preço de locação.
            if ($imovel['PrecoLocacao']) {
                $imovel['PrecoLocacao'] = convertToNumber($imovel['PrecoLocacao']);
            }

            // Analisando preço de temporada e convertendo-o a inteiro.
            if ($imovel['PrecoTemporada']) {
                $imovel['PrecoTemporada'] = convertToNumber($imovel['PrecoTemporada']);
            }

            // ValorIPTU não precisa de nenhuma análise no momento.
            // PrecoCondominio não precisa de nenhuma análise no momento.

            // Analisando TipoOferta. - Não há necessidade.
            //$imovel['TipoOferta'] = $this->parserOfferType($imovel['TipoOferta'], $imovel['PrecoLocacao'], $imovel['PrecoTemporada']);

            // Analisando GarantiaAluguel.
            if ($imovel['GarantiaAluguel']) {
                $imovel['GarantiaAluguel'] = $this->parserGuarantee($imovel['GarantiaAluguel']);
            }

            // Permuta não precisa de nenhuma análise no modelo imóvel guide.
            // Construtora não precisa de nenhuma análise no modelo imóvel guide.
            // Torres não precisa de nenhuma análise no modelo imóvel guide.
            // Andares não precisa de nenhuma análise no modelo imóvel guide.
            // UnidadesAndar não precisa de nenhuma análise no modelo imóvel guide.

            // Analisando Status do imóvel.
            if ($imovel['Novo']) {
                $imovel['Novo'] = $this->parserStatus($imovel['Novo']);
            }

            // AnoConstrucao não precisa de nenhuma análise no modelo imóvel guide.

            // Analisando AreaUtil.
            if ($imovel['AreaUtil']) {
                $imovel['AreaUtil'] = $this->parserAreaUtil($imovel['AreaUtil']);
            }

            // Analisando AreaConstruida.
            if ($imovel['AreaConstruida']) {
                $imovel['AreaConstruida'] = $this->parserAreaConstruida($imovel['AreaConstruida']);
            }

            // Analisando AreaTotal.
            if ($imovel['AreaTotal']) {
                $imovel['AreaTotal'] = $this->parserAreaTotal($imovel['AreaTotal']);
            }

            // Analisando AreaTerreno.
            if ($imovel['AreaTerreno']) {
                $imovel['AreaTerreno'] = $this->parserAreaTerreno($imovel['AreaTerreno']);
            }

            // AreaTerreno não precisa de nenhuma análise no modelo imóvel guide.
            // AreaConstruida não precisa de nenhuma análise no modelo imóvel guide.
            // QtdDormitorios não precisa de nenhuma análise no modelo imóvel guide.
            // QtdSuites não precisa de nenhuma análise no modelo imóvel guide.
            // QtdBanheiros não precisa de nenhuma análise no modelo imóvel guide.
            // QtdVagas não precisa de nenhuma análise no modelo imóvel guide.

            // Analisando as features.
            if (count($imovel['Features'])) {
                $imovel['Features'] = $this->parserFeatures($imovel['Features']);
            }

            // MostrarEndereco não precisa de nenhuma análise no modelo imóvel guide.

            // Analisando as features.
            if ($imovel['UF'] && mb_strlen($imovel['UF']) > 2) { // Cidade.
                $imovel['UF'] = $this->parserUF($imovel['UF']);
            }

            // Analisando string da cidade.
            $imovel['Cidade'] = unicode_conversor($imovel['Cidade']);

            // Analisando string do bairro.
            $imovel['Bairro'] = unicode_conversor($imovel['Bairro']);
            
            // BairroComercial não precisa de nenhuma análise no modelo imóvel guide.
            
            // Analisando CEP.
            $imovel['CEP'] = $this->parserCEP($imovel['CEP']);

            // Analisando string do bairro.
            if ($imovel['Endereco']) {
                $imovel['Endereco'] = str_replace(',', '', $imovel['Endereco']);
            }

            // Numero não precisa de nenhuma análise no modelo imóvel guide.
            // Complemento não precisa de nenhuma análise no modelo imóvel guide.
            // Latitude não precisa de nenhuma análise no modelo imóvel guide.
            // Longitude não precisa de nenhuma análise no modelo imóvel guide.
            // Spotlight não precisa de nenhuma análise no modelo imóvel guide.
            // Analisando string do area total.

            // Video não precisa de nenhuma análise no modelo imóvel guide.

            // Analisando string do imagens.
            if (count($imovel['images'])) { // Caso seja 0 por padrão, na hora da extração de dados, significa que não tem nada, então é null pra ser inserido na DB.
                $imovel['images'] = $this->parserImageUrl($imovel['images']);
            }

            // Inserindo no imóvel o title to imóvel.
            $imovelTitleAndSlug = $this->parserImovelTitleAndSlug($imovel);
            $imovel['ImovelTitle'] = $imovelTitleAndSlug['ImovelTitle'];
            $imovel['ImovelSlug'] = $imovelTitleAndSlug['ImovelSlug'];

            // Analisando link do youtube do imóvel. Verifica se é um link válido e direto pro youtube não permitindo outros links que não seja de vídeo pro youtube!
            if ($imovel['Video']) {
                $imovel['Video'] = $this->parserYoutubeVideo($imovel['Video']);
            }

            // Analisando o valor do metro quadrado do imóvel.
            $imovel['valor_m2'] = $this->parserValorM2($imovel['PrecoVenda'], $imovel['AreaUtil']);

            // Analisando id de negociação.
            $imovel['NegotiationId'] = $this->parserNegotiation($imovel);

            // Criando slugs do endereço(Cidade e Bairro).
            $imovel['CidadeSlug'] = Str::slug($imovel['Cidade']);
            $imovel['BairroSlug'] = Str::slug($imovel['Bairro']);

            // Fim de acordo com o modelo inglês(imovel guide e alguns outros que apenas segue o mesmo padrão) do jogão, o IntegrationService::imovelDataIngles.
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
        // Não tem metrô nas caracteristicas. - Não tem,só em descrição.
        $features['Piscina'] = $imovel->find('Piscina');
        $features['Ar Condicionado'] = $imovel->find('Arcondicionado');
        // Não tem Área verde nas caracteristicas. - Não tem,só em descrição.
        $features['Sacada'] = $imovel->find('Sacada');
        $features['Depósito'] = $imovel->find('Deposito');
        // Não tem segurança 24h nas caracteristicas. - Não tem, só na descrição.
        $features['Churrasqueira'] = $imovel->find('Churrasqueira');
        $features['Elevador'] = $imovel->find('Elevador');
        $features['Academia'] = $imovel->find('EmpreAcademia');
        $features['Salão de Festa'] = $imovel->find('Salafesta');
        $features['Playground'] = $imovel->find('Playground');
        // Não tem Piscina para Adulto Aberta nas caracteristicas. - Não tem.
        $features['Quadra Poliesportiva'] = $imovel->find('Quadrapoliesportiva');
        $features['Banheiro para Empregada'] = $imovel->find('Banheiroempregada');
        $features['Dormitório para Empregada'] = $imovel->find('Dormitoriosempregada');
        $features['Varanda Gourmet'] = $imovel->find('Varandagourmet');
        $features['Varanda'] = $imovel->find('Varanda');
        // Não tem Varanda Grill nas caracteristicas. - Não tem.
        $features['Armário de Cozinha Planejado'] = $imovel->find('Armariocozinha');
        $features['Armário do Quarto Planejado'] = $imovel->find('Armariodormitorio');
        // Não tem Varanda Técnica nas caracteristicas. - Não tem.
        // Não tem Piscina para Adulto Coberta nas caracteristicas. - Não tem.
        $features['Piscina Infantil Aberta'] = $imovel->find('Piscinainfantil');
        // Não tem Piscina Infantil Coberta nas caracteristicas. - Não tem.
        // Quadra de Tênis - Não tem.
        $features['Quadra de Squash'] = $imovel->find('Quadrasquash');
        $features['Campo de Futebol'] = $imovel->find('Campofutebol');
        // Campo de Golfe - Não tem.
        $features['Salão de Jogos'] = $imovel->find('Salajogos');
        // Espaço Pet - Não tem.
        // Sauna Seca - Não tem.
        $features['Sauna Úmida'] = $imovel->find('Sauna');
        $features['Brinquedoteca'] = $imovel->find('Brinquedoteca');
        // Estacionamento para Visitas - Não tem.
        $features['Frente para o Mar'] = $imovel->find('Frentemar');
        $features['Vista para o Mar'] = $imovel->find('Vistamar');
        // Sala de Massagem
        // Forno de Pizza - Não tem.
        // Portaria Blindada - Não tem.
        $features['Bicicletário'] = $imovel->find('Biciletario');
        // Lava-jato
        $features['Gerador'] = $imovel->find('Gerador');
        $features['Portaria 24h'] = $imovel->find('Portaria24horas');
        $features['Gás Encanado'] = $imovel->find('Gas');
        // Depósito Individual - Não tem.

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
        /*$offerType = strtolower(trim(preg_replace('/(\v|\s)+/', ' ', $offerType)));
        // Primeiro verifico a igualdade, para definir o tipo certo da oferta.
        if (in_array($offerType, ['sell', 'sale'])) { // Venda.
            return 1;
        } elseif($offerType == 'season') { // Temporada.
            return 4;
        } elseif ($offerType == 'rent') { // Aluguel.
            // TODO: Mover esse if e outros pra uma função pra ter uma legibilidade melhor.
            if ($precoLocacao > 0 && $precoTemporada > 0) {
                return 7;
            } else if($precoTemporada > 0) {
                return 4;
            } else {
                return 2;
            }
        } elseif ((str_contains($offerType, 'sell') || str_contains($offerType, 'sale')) && str_contains($offerType, 'rent')) {
            // TODO: Aqui também, mesmo do de cima.
            if ($precoLocacao > 0 && $precoTemporada > 0) {
                return 5;
            } else if($precoTemporada > 0) {
                return 6;
            } else {
                return 3;
            }
        } elseif ((str_contains($offerType, 'sell') || str_contains($offerType, 'sale')) && str_contains($offerType, 'rent') && str_contains($offerType, 'season')) {
            return 5;
        } elseif ((str_contains($offerType, 'sell') || str_contains($offerType, 'sale')) && str_contains($offerType, 'season')) {
            return 6;
        } elseif (str_contains($offerType, 'rent') && str_contains($offerType, 'season')) {
            return 7;
        } else {
            $this->toLog[] = "TipoOferta não identificada, o imóvel não foi inserido. Tipo de Oferta no XML: \"$offerType\" - trimed(com regex): \"$offerType\" - CodigoImovel(no XML) do Imóvel: {$this->imovelCode}.";
            return -1; // To skip later.
        }*/
    }

    protected function parserDescription(String $description) : String {
        $cleanedDescription = remove_emoji($description);

	    $cleanedDescription = trim($cleanedDescription); // Remove espaços em brancos do inicio e do fim da string.
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
            $url = trim(preg_replace('/\s\s+/', '', $url)); // Remove espaços em brancos a mais deixando apenas um a cada palavra.
            $url = filter_var($url, FILTER_SANITIZE_URL);
            if (!($url = filter_var($url, FILTER_VALIDATE_URL))) {
                $this->adsIMGNFound[] = $this->imovelCode;
                continue;
            }

            // $imageInfo = $this->getImageInfo($url);
            // dd($url, $this->data[0], $imageInfo);
            // if (!$imageInfo) {
            //     $this->toLog[] = "Não foi possível acessar a URL. URL no XML: \"$bckpUrl\" - CodigoImovel(no XML) do Imóvel: \"{$this->imovelCode}\".";
		    //     array_splice($images, $key, 1);
            //     continue;
            // }
            // if (strpos($imageInfo['content-type'], 'image/jpeg') === false || strpos($imageInfo['content-type'], 'image/jpg') === false) {
            //     $this->toLog[] = "A URL não contém uma imagem. URL no XML: \"$bckpUrl\" - CodigoImovel(no XML) do Imóvel: \"{$this->imovelCode}\".";
		    //     array_splice($images, $key, 1);
            //     continue;
            // }

            // $imageSize = $imageInfo['content-length'];
            // if (!is_numeric($imageSize) || (intval($imageSize) > $this->MAX_IMAGE_SIZE)) {
            //     $this->toLog[] = "A imagem é maior que ".$this->getMaxImgSize()." MB. Ela não será baixada, contate o dono(a) do XML. URL no XML: \"$bckpUrl\" - CodigoImovel(no XML) do Imóvel: \"{$this->imovelCode}\".";
            //     array_splice($images, $key, 1);
            //     continue;
            // }

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
            } else if($imovel['QtdDormitorios'] > 1) {
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
        } else if ($imovel['AreaConstruida'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaConstruida'], 0, ",", ".")." m²";
        } else if ($imovel['AreaTotal'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaTotal'], 0, ",", ".")." m²";
        } else if ($imovel['AreaTerreno'] > 0) {
            $imovelTitle = $imovelTitle.number_format($imovel['AreaTerreno'], 0, ",", ".")." m²";
        }
        
        if ($imovel['Bairro'] != null) {
            $imovelTitle = $imovelTitle." em ". ucwords(mb_strtolower($imovel['Bairro']));
            if ($imovel['Cidade'] != null) {
                $imovelTitle = $imovelTitle." - ".ucwords(mb_strtolower($imovel['Cidade']));
            }
        } else if ($imovel['Cidade'] != null) {
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

    // Insert xml data.
    protected function insertXmlData() : Void {
        $user_id = $this->integration->user->id;
        $userAnuncios = Anuncio::with(['endereco', 'condominiumData', 'anuncioBeneficio', 'gallery'])
        ->where('user_id', $user_id)
        ->where('xml', 1) // TODO: Add pra constante pra identificar melhor.
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
            if ($existingImovel) { // Verificando se o imóvel existe.
                if ($existingImovel->status === 'inativado') {
                    continue;
                }
                if ($this->isDifferentImovel($existingImovel, $newAnuncioInfo)) { // Caso exista, verificamos se o imóvel precisa ser atualizado ou não.
                    $newAnuncioInfo['updated_at'] = Carbon::now('America/Sao_Paulo');
                    $existingImovel->update($newAnuncioInfo);
                }

                $imovelId = $existingImovel->id;
            } else { // Caso não exista, inserimos ele do zero.
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

                                    // Salvar imagem original no S3
                                    $imageObject = Image::make($fileData);
                                    $originalData = $imageObject->encode('webp', 85)->getEncoded();
                                    Storage::disk('do_spaces')->put($s3Path, $originalData, 'public');

                                    // Também salvar localmente (temporário)
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
                            AnuncioImages::insert($imagesToInsert);
                            if ($this->isManual) {
                                echo "Imagem Nº: $index - Anúncio Código: {$imovel['CodigoImovel']}.\n";
                            }
                        }
                    }
                } else {
                    $oldImages = $existingImovel->gallery;
                    $toDownload = [];
                    $toCompare = [];

                    // Verifica se as imagens dentro da XML já foram inseridas no banco de dados.
                    foreach ($imovel['images'] as $key => $url) {
                        $imageFileName = 'integration/' . md5($user_id . $imovelId . basename($url)) . '.webp';
                        // Sempre fazer download das imagens para migração S3
                        // $hasImage = $oldImages->where('name', $imageFileName)->first();
                        $toCompare[] = $imageFileName;
                        // if (!$hasImage) {
                            $toDownload[] = ['url' => $url, 'imageFileName' => $imageFileName];
                        // }
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

                                    // Salvar imagem original no S3
                                    $imageObject = Image::make($fileData);
                                    $originalData = $imageObject->encode('webp', 85)->getEncoded();
                                    Storage::disk('do_spaces')->put($s3Path, $originalData, 'public');

                                    // Também salvar localmente (temporário)
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
                            AnuncioImages::insert($imagesToInsert);
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
            'system' => 'Union',
            'status' => 2,
            'qtd' => $this->imoveisCount,
            'updated_at' => Carbon::now()->toDateTimeString(),
            'last_integration' => Carbon::now()->toDateTimeString()
        ];

        /* if (!$this->integration->first_integration) {
            $integrationInfo['first_integration'] = Carbon::now()->toDateTimeString();

            $invite = new InviteService;
            $invite->givePointsForParentUser($user_id, 1, $this->imoveisCount);  
            $this->sendEmail($user_id);
        } */

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