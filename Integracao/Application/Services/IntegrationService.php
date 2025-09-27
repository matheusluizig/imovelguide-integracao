<?php

namespace App\Integracao\Application\Services;

use Illuminate\Support\Facades\DB;
use App\Services\AnuncioService;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Image;

class IntegrationService
{
  public static function imovelDataIngles($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = trim(IntegrationService::get_string_between($imovel, '<ListingID>', '</ListingID>'));
    if (empty($array['CodigoImovel'])) {
      $array['CodigoImovel'] = trim(
        IntegrationService::get_string_between($imovel, '<PropertyCode>', '</PropertyCode>')
      );
    }
    if (empty($array['CodigoImovel'])) {
      $array['CodigoImovel'] = trim(IntegrationService::get_string_between($imovel, '<imovelId>', '</imovelId>'));
    }
    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<PropertyType>', '</PropertyType>');
    if (empty($array['TipoImovel'])) {
      $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<category>', '</category>');
    }
    if (!empty($array['TipoImovel'])) {
      $array['TipoImovel'] = unicode_conversor($array['TipoImovel']);
    }
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    try {
      $imovel = explode('ContactInfo', $imovel);
      unset($imovel[1]);
      $imovel = implode('', $imovel);
    } catch (\Exception $e) {

    }
    $array['Descricao'] = trim(IntegrationService::get_string_between($imovel, '<Description>', '</Description>'));
    if (empty($array['Descricao'])) {
      $array['Descricao'] = IntegrationService::get_string_between($imovel, '<description>', '</description>');
    }
    if (!empty($array['Descricao'])) {
      $array['Descricao'] = IntegrationService::replaceAsc($array['Descricao']);
      $array['Descricao'] = trim(preg_replace('/\s+/', ' ', $array['Descricao']));
      if (!preg_match('//u', $array['Descricao'])) {
        $array['Descricao'] = utf8_encode($array['Descricao']);
      }
    }
    $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<Title>', '</Title>');
    if (empty($array['Subtitle'])) {
      $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<title>', '</title>');
    }

    $array['PrecoVenda'] = IntegrationService::get_string_between(
      $imovel,
      '<ListPrice currency="BRL">',
      '</ListPrice>'
    );
    if (empty($array['PrecoVenda'])) {
      $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<ListPrice>', '</ListPrice>');
    }
    if (empty($array['PrecoVenda'])) {
      $array['PrecoVenda'] = IntegrationService::get_string_between(
        $imovel,
        "<ListPrice currency='BRL'>",
        '</ListPrice>'
      );
    }
    if (empty($array['PrecoVenda'])) {
      $array['PrecoVenda'] = intval(IntegrationService::get_string_between($imovel, '<price>', '</price>'));
    }
    $array['PrecoLocacao'] = IntegrationService::get_string_between(
      $imovel,
      '<RentalPrice currency="BRL">',
      '</RentalPrice>'
    );
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<RentPrice>', '</RentPrice>');
    }
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<RentalPrice>', '</RentalPrice>');
    }
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between(
        $imovel,
        '<RentalPrice currency="BRL" period="Monthly">',
        '</RentalPrice>'
      );
    }
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between(
        $imovel,
        "<RentalPrice currency='BRL' period='Monthly'>",
        '</RentalPrice>'
      );
    }
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between(
        $imovel,
        '<RentalPrice currency="Monthly">',
        '</RentalPrice>'
      );
    }
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between(
        $imovel,
        '<RentalPrice currency="1">',
        '</RentalPrice>'
      );
    }
    if (empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = IntegrationService::get_string_between(
        $imovel,
        '<RentalPrice currency="BRL" period="Weekly">',
        '</RentalPrice>'
      );
      if (!empty($array['PrecoLocacao'])) {
        $array['PrecoLocacao'] = $array['PrecoLocacao'] * 4;
      }
    }
    $array['PrecoTemporada'] = IntegrationService::get_string_between($imovel, '<SeasonPrice>', '</SeasonPrice>');
    if (empty($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = IntegrationService::get_string_between(
        $imovel,
        '<RentalPrice currency="1" period="Daily">',
        '</RentalPrice>'
      );
    }
    $array['ValorIPTU'] = IntegrationService::get_string_between($imovel, '<YearlyTax>', '</YearlyTax>');
    if (empty($array['ValorIPTU'])) {
      $array['ValorIPTU'] = IntegrationService::get_string_between(
        $imovel,
        '<YearlyTax currency="BRL">',
        '</YearlyTax>'
      );
    }
    if (empty($array['ValorIPTU'])) {
      $array['ValorIPTU'] = IntegrationService::get_string_between(
        $imovel,
        "<YearlyTax currency='BRL'>",
        '</YearlyTax>'
      );
    }
    $array['PrecoCondominio'] = IntegrationService::get_string_between(
      $imovel,
      '<PropertyAdministrationFee>',
      '</PropertyAdministrationFee>'
    );
    if (empty($array['PrecoCondominio'])) {
      $array['PrecoCondominio'] = IntegrationService::get_string_between(
        $imovel,
        '<PropertyAdministrationFee currency="BRL">',
        '</PropertyAdministrationFee>'
      );
    }
    if (empty($array['PrecoCondominio'])) {
      $array['PrecoCondominio'] = IntegrationService::get_string_between(
        $imovel,
        '<PropertyAdministrationFee currency="BRL" period="Daily">',
        '</PropertyAdministrationFee>'
      );
    }
    if (empty($array['PrecoCondominio'])) {
      $array['PrecoCondominio'] = IntegrationService::get_string_between(
        $imovel,
        "<PropertyAdministrationFee currency='BRL'>",
        '</PropertyAdministrationFee>'
      );
    }
    $array['TipoOferta'] = trim(
      preg_replace(
        '/(\v|\s)+/',
        ' ',
        IntegrationService::get_string_between($imovel, '<TransactionType>', '</TransactionType>')
      )
    );
    if (!empty(str_replace('<![CDATA[', '', str_replace(']]>', '', $array['TipoOferta'])))) {
      if (
        $array['TipoOferta'] == 'Venda' ||
        $array['TipoOferta'] == 'For Sale' ||
        $array['TipoOferta'] == 'Sale' ||
        $array['TipoOferta'] == '<![CDATA[Sale]]>' ||
        $array['TipoOferta'] == '<![CDATA[For Sale]]>'
      ) {
        $array['TipoOferta'] = 1;
      } elseif (
        $array['TipoOferta'] == 'Aluguel Fixo' ||
        $array['TipoOferta'] == 'Aluguel' ||
        $array['TipoOferta'] == 'For Rent' ||
        $array['TipoOferta'] == 'Rent' ||
        $array['TipoOferta'] == '<![CDATA[Rent]]>' ||
        $array['TipoOferta'] == '<![CDATA[For Rent]]>'
      ) {
        if ($array['PrecoLocacao'] > 0 && $array['PrecoTemporada'] > 0) {
          $array['TipoOferta'] = 7;
        } elseif ($array['PrecoTemporada'] > 0) {
          $array['TipoOferta'] = 4;
        } else {
          $array['TipoOferta'] = 2;
        }
      } elseif (
        $array['TipoOferta'] == 'Sale/Rent' ||
        $array['TipoOferta'] == '<![CDATA[Sale/Rent]]>' ||
        $array['TipoOferta'] == 'Rent/Sale' ||
        $array['TipoOferta'] == '<![CDATA[Rent/Sale]]>'
      ) {
        if ($array['PrecoLocacao'] > 0 && $array['PrecoTemporada'] > 0) {
          $array['TipoOferta'] = 5;
        } elseif ($array['PrecoTemporada'] > 0) {
          $array['TipoOferta'] = 6;
        } else {
          $array['TipoOferta'] = 3;
        }
      } elseif ($array['TipoOferta'] == 'Season' || $array['TipoOferta'] == '<![CDATA[Season]]>') {
        $array['TipoOferta'] = 4;
      } elseif (
        $array['TipoOferta'] == 'Sale/Rent/Season' ||
        $array['TipoOferta'] == 'Season/Sale/Rent' ||
        $array['TipoOferta'] == 'Season/Rent/Sale' ||
        $array['TipoOferta'] == '<![CDATA[Rent/Sale/Season]]>'
      ) {
        $array['TipoOferta'] = 5;
      } elseif (
        $array['TipoOferta'] == 'Sale/Season' ||
        $array['TipoOferta'] == 'Season/Sale' ||
        $array['TipoOferta'] == '<![CDATA[Sale/Season]]>'
      ) {
        $array['TipoOferta'] = 6;
      } elseif (
        $array['TipoOferta'] == 'Rent/Season' ||
        $array['TipoOferta'] == 'Season/Rent' ||
        $array['TipoOferta'] == 'Locado' ||
        $array['TipoOferta'] == 'Aluguel Temporada' ||
        $array['TipoOferta'] == '<![CDATA[Rent/Season]]>'
      ) {
        $array['TipoOferta'] = 7;
      } else {
        if (!$log) {
          dd(
            "ERRO Tipo de Oferta, Ingles\nCodigo do Anuncio: " .
              $array['CodigoImovel'] .
              "\nTipo de Oferta:" .
              $array['TipoOferta'] .
              "\n"
          );
        } else {
          fwrite(
            $log,
            "ERRO Tipo de Oferta, Ingles\nCodigo do Anuncio: " .
              $array['CodigoImovel'] .
              "\nTipo de Oferta:" .
              $array['TipoOferta'] .
              "\n"
          );
        }
        return false;
      }
    } else {
      if (stristr($imovel, 'Venda</category>') == true) {
        $array['TipoOferta'] = 1;
        $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<price>', '</price>');
      } elseif (stristr($imovel, 'Aluguel</category>') == true) {
        $array['TipoOferta'] = 2;
        $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<price>', '</price>');
      } else {
        $array['TipoOferta'] = IntegrationService::detectNegotiation(
          $array['PrecoVenda'],
          $array['PrecoLocacao'],
          $array['PrecoTemporada']
        );
        if (empty($array['TipoOferta'])) {
          if (!$log) {
            dd(
              "ERRO Tipo de Oferta Bugada, Ingles\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Oferta:" .
                $array['TipoOferta'] .
                "\n"
            );
          } else {
            fwrite(
              $log,
              "ERRO Tipo de Oferta Bugada, Ingles\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Oferta:" .
                $array['TipoOferta'] .
                "\n"
            );
          }
          return false;
        }
      }
    }
    $array['GarantiaAluguel'] = IntegrationService::get_string_between(
      $imovel,
      '<RentalGuarantee>',
      '</RentalGuarantee>'
    );
    if (!empty($array['GarantiaAluguel'])) {
      switch ($array['GarantiaAluguel']) {
        case 'Sem Garantia':
          $array['GarantiaAluguel'] = 0;
          break;
        case 'Depósito caução':
          $array['GarantiaAluguel'] = 1;
          break;
        case 'Seguro fiança':
          $array['GarantiaAluguel'] = 2;
          break;
        case 'Carta fiança':
          $array['GarantiaAluguel'] = 3;
          break;
        case 'Fiador':
          $array['GarantiaAluguel'] = 4;
          break;
        case 'Titulo de capitalização':
          $array['GarantiaAluguel'] = 5;
          break;
        default:
          if (stristr($array['GarantiaAluguel'], 'Carta fiança') == true) {
            $array['GarantiaAluguel'] = 3;
          }
          if (stristr($array['GarantiaAluguel'], 'Titulo de capitalização') == true) {
            $array['GarantiaAluguel'] = 5;
          }
          if (stristr($array['GarantiaAluguel'], 'Fiador') == true) {
            $array['GarantiaAluguel'] = 4;
          }
          if (stristr($array['GarantiaAluguel'], 'Seguro fiança') == true) {
            $array['GarantiaAluguel'] = 2;
          }
          if (stristr($array['GarantiaAluguel'], 'Depósito caução') == true) {
            $array['GarantiaAluguel'] = 1;
          }
          break;
      }
    } else {
      $garantias = IntegrationService::get_string_between($imovel, '<Warranties>', '</Warranties>');
      if (stristr($garantias, '<Warranty>GUARANTEE_LETTER</Warranty>') == true) {
        $array['GarantiaAluguel'] = 3;
      }
      if (stristr($garantias, '<Warranty>CAPITALIZATION_BONDS</Warranty>') == true) {
        $array['GarantiaAluguel'] = 5;
      }
      if (stristr($garantias, '<Warranty>GUARANTOR</Warranty>') == true) {
        $array['GarantiaAluguel'] = 4;
      }
      if (stristr($garantias, '<Warranty>INSURANCE_GUARANTEE</Warranty>') == true) {
        $array['GarantiaAluguel'] = 2;
      }
      if (stristr($garantias, '<Warranty>SECURITY_DEPOSIT</Warranty>') == true) {
        $array['GarantiaAluguel'] = 1;
      }
    }
    if (empty($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = 0;
    }
    $array['Permuta'] = IntegrationService::get_string_between($imovel, '<Exchange>', '</Exchange>');

    $array['Construtora'] = IntegrationService::get_string_between($imovel, '<Builder>', '</Builder>');
    $array['Torres'] = IntegrationService::get_string_between($imovel, '<Towers>', '</Towers>');
    $array['Andares'] = IntegrationService::get_string_between($imovel, '<Floors>', '</Floors>');
    $array['UnidadesAndar'] = IntegrationService::get_string_between($imovel, '<UnitsPerFloor>', '</UnitsPerFloor>');

    $array['Novo'] = IntegrationService::get_string_between($imovel, '<NewProperty>', '</NewProperty>');
    if (!empty($array['Novo'])) {
      switch ($array['Novo']) {
        case 'Usado':
          $array['Novo'] = 0;
          break;
        case 'Novo':
          $array['Novo'] = 1;
          break;
        case 'Construção':
          $array['Novo'] = 2;
          break;
        case 'Lançamento':
          $array['Novo'] = 3;
          break;
      }
    }
    if (empty($array['Novo'])) {
      $array['Novo'] = 0;
    }
    $array['AnoConstrucao'] = IntegrationService::get_string_between($imovel, '<YearBuilt>', '</YearBuilt>');
    if (empty($array['AnoConstrucao'])) {
      $array['AnoConstrucao'] = IntegrationService::get_string_between(
        $imovel,
        '<ConstructionYear>',
        '</ConstructionYear>'
      );
    }
    $array['AreaUtil'] = IntegrationService::get_string_between(
      $imovel,
      '<LivingArea unit="square metres">',
      '</LivingArea>'
    );
    if (empty($array['AreaUtil'])) {
      $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<LivingArea>', '</LivingArea>');
    }
    if (empty($array['AreaUtil'])) {
      $array['AreaUtil'] = IntegrationService::get_string_between(
        $imovel,
        "<LivingArea unit='square metres'>",
        '</LivingArea>'
      );
    }
    if (empty($array['AreaUtil'])) {
      $array['AreaUtil'] = IntegrationService::get_string_between(
        $imovel,
        '<LivingArea currency="square metres">',
        '</LivingArea>'
      );
    }
    if (empty($array['AreaUtil'])) {
      $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<name>Área útil</name><value>', '</value>');
    }
    if (empty($array['AreaUtil'])) {
      $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<name>Área útil</name><value>', '<value>');
    }
    if (!empty($array['AreaUtil'])) {
      $array['AreaUtil'] = str_replace('m', '', $array['AreaUtil']);
      $array['AreaUtil'] = str_replace('²', '', $array['AreaUtil']);
    }
    $array['AreaTerreno'] = IntegrationService::get_string_between(
      $imovel,
      '<LotArea unit="square metres">',
      '</LotArea>'
    );
    if (empty($array['AreaTerreno'])) {
      $array['AreaTerreno'] = IntegrationService::get_string_between($imovel, '<LotArea>', '</LotArea>');
    }
    if (empty($array['AreaTerreno'])) {
      $array['AreaTerreno'] = IntegrationService::get_string_between(
        $imovel,
        '<LotArea currency="square metres">',
        '</LotArea>'
      );
    }
    $array['AreaConstruida'] = IntegrationService::get_string_between(
      $imovel,
      '<ConstructedArea unit="square metres">',
      '</ConstructedArea>'
    );
    if (empty($array['AreaConstruida'])) {
      $array['AreaConstruida'] = IntegrationService::get_string_between(
        $imovel,
        '<ConstructedArea currency="square metres">',
        '</ConstructedArea>'
      );
    }
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<Bedrooms>', '</Bedrooms>');
    if (empty($array['QtdDormitorios'])) {
      $array['QtdDormitorios'] = IntegrationService::get_string_between(
        $imovel,
        '<name>Quartos</name><value>',
        '</value>'
      );
    }
    if (empty($array['QtdDormitorios'])) {
      $array['QtdDormitorios'] = IntegrationService::get_string_between(
        $imovel,
        '<name>Quartos</name><value>',
        '<value>'
      );
    }
    $array['QtdSuites'] = IntegrationService::get_string_between($imovel, '<Suites>', '</Suites>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<Bathrooms>', '</Bathrooms>');
    if (empty($array['QtdBanheiros'])) {
      $array['QtdBanheiros'] = IntegrationService::get_string_between(
        $imovel,
        '<name>Banheiros</name><value>',
        '</value>'
      );
    }
    if (empty($array['QtdBanheiros'])) {
      $array['QtdBanheiros'] = IntegrationService::get_string_between(
        $imovel,
        '<name>Banheiros</name><value>',
        '<value>'
      );
    }
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<Garage type="Parking Space">', '</Garage>');
    if (empty($array['QtdVagas'])) {
      $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<Garage>', '</Garage>');
    }
    if (empty($array['QtdVagas'])) {
      $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<Garage type="Garage">', '</Garage>');
    }
    if (empty($array['QtdVagas'])) {
      $array['QtdVagas'] = IntegrationService::get_string_between(
        $imovel,
        "<Garage type='Parking Space'>",
        '</Garage>'
      );
    }

    $caracteristicas = IntegrationService::get_string_between($imovel, '<Features>', '</Features>');
    $caracteristicas = str_replace('<![CDATA[', '', $caracteristicas);
    $caracteristicas = str_replace(']]>', '', $caracteristicas);
    if (stristr($caracteristicas, '<Feature>Cooling</Feature>') == true) {
      $array['ArCondicionado'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Air Conditioning</Feature>') == true) {
      $array['ArCondicionado'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Ar Condicionado</Feature>') == true) {
      $array['ArCondicionado'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Quarto Deposito</Feature>') == true) {
      $array['Deposito'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Warehouse</Feature>') == true) {
      $array['Deposito'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Planned Kitchen Cabinet</Feature>') == true) {
      $array['ArmarioPlanCozinha'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>WC Serviço</Feature>') == true) {
      $array['BanheiroEmpregada'] = 1;
    }
    if (stristr($caracteristicas, "<Feature>Maid's Bathroom</Feature>") == true) {
      $array['BanheiroEmpregada'] = 1;
    }
    if (stristr($caracteristicas, "<Feature>Maid's Quarters</Feature>") == true) {
      $array['DormitorioEmpregada'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Balcony</Feature>') == true) {
      $array['Sacada'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Veranda</Feature>') == true) {
      $array['Varanda'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Balcony Grill</Feature>') == true) {
      $array['VarandaGrill'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Gourmet Balcony</Feature>') == true) {
      $array['VarandaGourmet'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Technical Balcony</Feature>') == true) {
      $array['VarandaTecnica'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Elevator</Feature>') == true) {
      $array['Elevador'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Pool</Feature>') == true) {
      $array['Piscina'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Piscina</Feature>') == true) {
      $array['Piscina'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Swimming Pool</Feature>') == true) {
      $array['Piscina'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Open Adult Pool</Feature>') == true) {
      $array['Piscina'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Indoor Adult Pool</Feature>') == true) {
      $array['PiscinaAdultoCoberta'] = 1;
    }
    if (stristr($caracteristicas, "<Feature>Open Children's Pool</Feature>") == true) {
      $array['PiscinaInfantilAberta'] = 1;
    }
    if (stristr($caracteristicas, "<Feature>Indoor Children's Pool</Feature>") == true) {
      $array['PiscinaInfantilCoberta'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Gym</Feature>') == true) {
      $array['Academia'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Gyn</Feature>') == true) {
      $array['Academia'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Sports Court</Feature>') == true) {
      $array['QuadraPoliesportiva'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Tennis court</Feature>') == true) {
      $array['QuadraTenis'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Tennis Court</Feature>') == true) {
      $array['QuadraTenis'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Squash</Feature>') == true) {
      $array['QuadraSquash'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Squash Court</Feature>') == true) {
      $array['QuadraSquash'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Football Field</Feature>') == true) {
      $array['CampoFutebol'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Golf Course</Feature>') == true) {
      $array['CampoGolf'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Game room</Feature>') == true) {
      $array['SalaoJogos'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Game Room</Feature>') == true) {
      $array['SalaoJogos'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Playground</Feature>') == true) {
      $array['Playground'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Pet Space</Feature>') == true) {
      $array['EspacoPet'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>BBQ</Feature>') == true) {
      $array['Churrasqueira'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Churrasqueira</Feature>') == true) {
      $array['Churrasqueira'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Sauna</Feature>') == true) {
      $array['SaunaUmida'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Wet Sauna</Feature>') == true) {
      $array['SaunaSeca'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Party Room</Feature>') == true) {
      $array['SalaoFesta'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Playroom</Feature>') == true) {
      $array['Brinquedoteca'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Guest Parking</Feature>') == true) {
      $array['EstacionaVisitas'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Facing the Sea</Feature>') == true) {
      $array['FrenteMar'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Ocean View</Feature>') == true) {
      $array['VistaMar'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Sea view</Feature>') == true) {
      $array['VistaMar'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Sea View</Feature>') == true) {
      $array['VistaMar'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Massage Room</Feature>') == true) {
      $array['SalaMassagem'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Pizza Oven</Feature>') == true) {
      $array['FornoPizza'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Security Guard on Duty</Feature>') == true) {
      $array['Seguranca'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>24 Hour Security</Feature>') == true) {
      $array['Seguranca'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Armored Gate</Feature>') == true) {
      $array['PortatiraBlindada'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Bike Rack</Feature>') == true) {
      $array['Bicicletario'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Car Wash</Feature>') == true) {
      $array['CarWash'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Generator</Feature>') == true) {
      $array['Gerador'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>24 Hour Concierge</Feature>') == true) {
      $array['Portaria24'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Gas Pipeline</Feature>') == true) {
      $array['GasEncanado'] = 1;
    }
    if (stristr($caracteristicas, '<Feature>Individual Warehouse</Feature>') == true) {
      $array['DepositoIndividual'] = 1;
    }

    $array['MostrarEndereco'] = IntegrationService::get_string_between($imovel, '<Location displayAddress="', '">');
    $array['MostrarEndereco'] = IntegrationService::verifyShowAddress($array['MostrarEndereco'], $log);
    $array['UF'] = IntegrationService::get_string_between($imovel, '<State abbreviation="', '">');
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, '<State abbreviation = "', '">');
    }
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, "<State abbreviation='", "'>");
    }
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, '<State>', '</State>');
    }
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, '<state>', '</state>');
    }
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, "<State abbreviation='", "'>");
    }
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<City>', '</City>');
    if (empty($array['Cidade'])) {
      $array['Cidade'] = IntegrationService::get_string_between($imovel, '<city>', '</city>');
    }
    if (!empty($array['Cidade'])) {
      $array['Cidade'] = unicode_conversor($array['Cidade']);
    }
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<Neighborhood>', '</Neighborhood>');
    if (empty($array['Bairro'])) {
      $array['Bairro'] = IntegrationService::get_string_between($imovel, '<neighborhood>', '</neighborhood>');
    }
    if (!empty($array['Bairro'])) {
      $array['Bairro'] = IntegrationService::replaceAsc($array['Bairro']);
    }
    if (!empty($array['Bairro'])) {
      $array['Bairro'] = unicode_conversor($array['Bairro']);
    }
    $array['BairroComercial'] = IntegrationService::get_string_between(
      $imovel,
      '<BusinessDistrict>',
      '</BusinessDistrict>'
    );
    if (!empty($array['BairroComercial'])) {
      $array['BairroComercial'] = IntegrationService::replaceAsc($array['BairroComercial']);
    }
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<PostalCode>', '</PostalCode>');
    if (empty($array['CEP'])) {
      $array['CEP'] = IntegrationService::get_string_between($imovel, '<zipCode>', '</zipCode>');
    }
    $array['Endereco'] = IntegrationService::get_string_between($imovel, '<Address>', '</Address>');
    if (empty($array['Endereco'])) {
      $array['Endereco'] = IntegrationService::get_string_between($imovel, '<addressLine>', '</addressLine>');
    }
    if (!empty($array['Endereco'])) {
      $array['Endereco'] = str_replace(',', '', $array['Endereco']);
    }
    $array['Numero'] = IntegrationService::get_string_between($imovel, '<StreetNumber>', '</StreetNumber>');
    $array['Complemento'] = IntegrationService::get_string_between($imovel, '<Complement>', '</Complement>');
    $array['Latitude'] = IntegrationService::get_string_between($imovel, '<Latitude>', '</Latitude>');
    if (empty($array['Latitude'])) {
      $array['Latitude'] = IntegrationService::get_string_between($imovel, '<latitude>', '</latitude>');
    }
    $array['Longitude'] = IntegrationService::get_string_between($imovel, '<Longitude>', '</Longitude>');
    if (empty($array['Longitude'])) {
      $array['Longitude'] = IntegrationService::get_string_between($imovel, '<longitude>', '</longitude>');
    }

    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['AreaTotal'])) {
      $array['AreaTotal'] = null;
    }

    $array['Video'] = IntegrationService::get_string_between($imovel, '<Item medium="video">', '</Item>');
    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = trim(str_replace('<![CDATA[', '', $array[$index]));
        $array[$index] = trim(str_replace(']]>', '', $array[$index]));
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<Media>', '</Media>');
    if (empty($foto)) {
      $foto = IntegrationService::get_string_between($imovel, '<pictures>', '</pictures>');
    }
    $foto = str_replace("\n", '', $foto);

    if (stristr($foto, "<Item medium='imagem'>") == true) {
      $foto = explode("<Item medium='imagem'>", $foto);
    } elseif (stristr($foto, '<Item caption="" medium="image"') == true) {
      $foto = explode('<Item caption="" medium="image"', $foto);
    } elseif (stristr($foto, '<Item medium="image"') == true) {
      $foto = explode('<Item medium="image"', $foto);
    } elseif (stristr($foto, "<Item medium='image'") == true) {
      $foto = explode("<Item medium='image'", $foto);
    } elseif (stristr($foto, '<imageURL>') == true) {
      $foto = explode('<imageURL>', $foto);
    } elseif (stristr($foto, '<Item caption="') == true) {
      $foto = explode('<Item caption="', $foto);
    } else {
      $foto = explode('<Item>', $foto);
    }
    foreach ($foto as $index => $f) {
      $foto[$index] = substr($f, strpos($f, 'http'));
      $foto[$index] = trim(str_replace('</Item>', '', $foto[$index]));
      $foto[$index] = trim(str_replace('</imageURL>', '', $foto[$index]));
    }
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }
      $fotos['name'] = trim($f);
      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
      $galeria[$index] = str_replace('>"', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataImobIo($imovel, $log)
  {
    $array = [];
    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<property_type>', '</property_type>');
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['Descricao'] = trim(IntegrationService::get_string_between($imovel, '<content>', '</content>'));
    if (!empty($array['Descricao'])) {
      $array['Descricao'] = IntegrationService::replaceAsc($array['Descricao']);
    }

    $array['TipoOferta'] = IntegrationService::get_string_between($imovel, '<type>', '</type>');
    $array['PrecoVenda'] = null;
    $array['PrecoLocacao'] = null;
    if ($array['TipoOferta'] == '<![CDATA[Venda]]>') {
      $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<price>', '</price>');
      $array['TipoOferta'] = 1;
    } else {
      if (!$log) {
        dd(
          "ERRO Tipo de Oferta, ImobIO\nCodigo do Anuncio: " .
            $array['CodigoImovel'] .
            "\nTipo de Oferta:" .
            $array['TipoOferta'] .
            "\n"
        );
      } else {
        fwrite(
          $log,
          "ERRO Tipo de Oferta, ImobIO\nCodigo do Anuncio: " .
            $array['CodigoImovel'] .
            "\nTipo de Oferta:" .
            $array['TipoOferta'] .
            "\n"
        );
      }
      return false;
      if ($array['TipoOferta'] == '<![CDATA[Locação]]>') {
      } else {
      }
    }

    $array['CodigoImovel'] = trim(IntegrationService::get_string_between($imovel, '<id>', '</id>'));
    $array['AreaTerreno'] = IntegrationService::get_string_between($imovel, '<plot_area>', '</plot_area>');
    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<flor_area>', '</flor_area>');
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<rooms>', '</rooms>');
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<parking>', '</parking>');

    $array['UF'] = IntegrationService::get_string_between($imovel, '<region>', '</region>');
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<city>', '</city>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<city_area>', '</city_area>');

    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['Subtitle'])) {
      $array['Subtitle'] = null;
    }
    if (!isset($array['AreaTotal'])) {
      $array['AreaTotal'] = null;
    }
    if (!isset($array['AreaUtil'])) {
      $array['AreaUtil'] = 0;
    }
    if (!isset($array['QtdSuites'])) {
      $array['QtdSuites'] = null;
    }
    if (!isset($array['QtdBanheiros'])) {
      $array['QtdBanheiros'] = 0;
    }
    if (!isset($array['ValorIPTU'])) {
      $array['ValorIPTU'] = null;
    }
    if (!isset($array['CEP'])) {
      $array['CEP'] = 0;
    }
    if (!isset($array['Endereco'])) {
      $array['Endereco'] = '';
    }
    if (!isset($array['Numero'])) {
      $array['Numero'] = null;
    }
    if (!isset($array['AreaConstruida'])) {
      $array['AreaConstruida'] = null;
    }
    if (!isset($array['Permuta'])) {
      $array['Permuta'] = 0;
    }
    if (!isset($array['Latitude'])) {
      $array['Latitude'] = null;
    }
    if (!isset($array['Longitude'])) {
      $array['Longitude'] = null;
    }
    if (!isset($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = null;
    }
    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = null;
    }
    if (!isset($array['Novo'])) {
      $array['Novo'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['UnidadesAndar'])) {
      $array['UnidadesAndar'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['Video'])) {
      $array['Video'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['Complemento'])) {
      $array['Complemento'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }

    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<pictures>', '</pictures>');
    $foto = str_replace("\n", '', $foto);
    $foto = explode('<picture>', $foto);
    foreach ($foto as $index => $f) {
      $foto[$index] = IntegrationService::get_string_between($f, '<picture_url>', '</picture_url>');
    }
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }
      $fotos['name'] = trim($f);

      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
      $galeria[$index] = str_replace('>"', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataUnion($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<Codigoimovel>', '</Codigoimovel>');
    $array['Descricao'] = trim(
      IntegrationService::get_string_between($imovel, '<Anuncioparainternet>', '</Anuncioparainternet>')
    );
    $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<Titulo>', '</Titulo>');
    $array['KeyWords'] = IntegrationService::get_string_between($imovel, '<PalavraschavesSEO>', '</PalavraschavesSEO>');
    $array['Spotlight'] = IntegrationService::get_string_between($imovel, '<Destaque>', '</Destaque>');

    $array['Permuta'] = IntegrationService::get_string_between($imovel, '<Permuta>', '</Permuta>');
    $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<Valorvenda>', '</Valorvenda>');
    if (!empty($array['PrecoVenda'])) {
      $array['PrecoVenda'] = substr($array['PrecoVenda'], 0, strrpos($array['PrecoVenda'], '.'));
    }
    $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<Valorlocacao>', '</Valorlocacao>');
    if (!empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = substr($array['PrecoLocacao'], 0, strrpos($array['PrecoLocacao'], '.'));
    }
    $array['PrecoTemporada'] = IntegrationService::get_string_between($imovel, '<Valortemporada>', '</Valortemporada>');
    if (!empty($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = substr($array['PrecoTemporada'], 0, strrpos($array['PrecoTemporada'], '.'));
    }
    $array['ValorIPTU'] = IntegrationService::get_string_between($imovel, '<Valoriptu>', '</Valoriptu>');
    $array['PrecoCondominio'] = IntegrationService::get_string_between(
      $imovel,
      '<Valorcondominio>',
      '</Valorcondominio>'
    );
    if (!empty($array['PrecoCondominio'])) {
      $array['PrecoCondominio'] = substr($array['PrecoCondominio'], 0, strrpos($array['PrecoCondominio'], '.'));
    }
    $venda = IntegrationService::get_string_between($imovel, '<Venda>', '</Venda>');
    $aluguel = IntegrationService::get_string_between($imovel, '<Locacao>', '</Locacao>');
    $temporada = IntegrationService::get_string_between($imovel, '<Temporada>', '</Temporada>');
    if (
      !(empty($venda) || $venda == 0) &&
      !(empty($aluguel) || $aluguel == 0) &&
      !(empty($temporada) || $temporada == 0)
    ) {
      $array['TipoOferta'] = 5;
    } elseif (!(empty($venda) || $venda == 0)) {
      if (!(empty($aluguel) || $aluguel == 0)) {
        $array['TipoOferta'] = 3;
      } elseif (!(empty($temporada) || $temporada == 0)) {
        $array['TipoOferta'] = 6;
      } else {
        $array['TipoOferta'] = 1;
      }
    } elseif (!(empty($aluguel) || $aluguel == 0)) {
      if (!(empty($temporada) || $temporada == 0)) {
        $array['TipoOferta'] = 7;
      } else {
        $array['TipoOferta'] = 2;
      }
    } elseif (!(empty($temporada) || $temporada == 0)) {
      $array['TipoOferta'] = 4;
    } else {
      $array['TipoOferta'] = 1;
    }

    $array['Construtora'] = IntegrationService::get_string_between($imovel, '<Construtora>', '</Construtora>');
    $array['Andares'] = IntegrationService::get_string_between($imovel, '<Andares>', '</Andares>');

    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<Tipo>', '</Tipo>');
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['AnoConstrucao'] = IntegrationService::get_string_between($imovel, '<AnoConstrucao>', '</AnoConstrucao>');
    $array['AreaUtil'] = IntegrationService::get_string_between(
      $imovel,
      '<Areautilsemdeciamal>',
      '</Areautilsemdeciamal>'
    );
    if (empty($array['AreaUtil'])) {
      $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<Areautil>', '</Areautil>');
    }
    $array['AreaTotal'] = IntegrationService::get_string_between(
      $imovel,
      '<Areatotalsemdeciamal>',
      '</Areatotalsemdeciamal>'
    );
    if (empty($array['AreaTotal'])) {
      $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<Areatotal>', '</Areatotal>');
    }
    $array['AreaTerreno'] = IntegrationService::get_string_between(
      $imovel,
      '<Areaterrenosemdeciamal>',
      '</Areaterrenosemdeciamal>'
    );
    if (empty($array['AreaTerreno'])) {
      $array['AreaTerreno'] = IntegrationService::get_string_between($imovel, '<Areaterreno>', '</Areaterreno>');
    }
    $array['AreaConstruida'] = IntegrationService::get_string_between(
      $imovel,
      '<Areacosntruidasemdeciamal>',
      '</Areacosntruidasemdeciamal>'
    );
    if (empty($array['AreaConstruida'])) {
      $array['AreaConstruida'] = IntegrationService::get_string_between(
        $imovel,
        '<Areacosntruida>',
        '</Areacosntruida>'
      );
    }
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<Dormitorios>', '</Dormitorios>');
    $array['QtdSuites'] = IntegrationService::get_string_between($imovel, '<Suite>', '</Suite>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<Banheiro2>', '</Banheiro2>');
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<Garagem>', '</Garagem>');

    $array['ArCondicionado'] = IntegrationService::get_string_between($imovel, '<Arcondicionado>', '</Arcondicionado>');
    $array['Deposito'] = IntegrationService::get_string_between($imovel, '<Deposito>', '</Deposito>');
    $array['Piscina'] = IntegrationService::get_string_between($imovel, '<Piscina>', '</Piscina>');
    $array['PiscinaInfantilAberta'] = IntegrationService::get_string_between(
      $imovel,
      '<Piscinainfantil>',
      '</Piscinainfantil>'
    );
    $array['Sacada'] = IntegrationService::get_string_between($imovel, '<Sacada>', '</Sacada>');
    $array['Churrasqueira'] = IntegrationService::get_string_between($imovel, '<Churrasqueira>', '</Churrasqueira>');
    $array['Elevador'] = IntegrationService::get_string_between($imovel, '<Elevador>', '</Elevador>');
    $array['Academia'] = IntegrationService::get_string_between($imovel, '<EmpreAcademia>', '</EmpreAcademia>');
    $array['SalaoFesta'] = IntegrationService::get_string_between($imovel, '<Salafesta>', '</Salafesta>');
    $array['Playground'] = IntegrationService::get_string_between($imovel, '<Playground>', '</Playground>');
    $array['QuadraPoliesportiva'] = IntegrationService::get_string_between(
      $imovel,
      '<Quadrapoliesportiva>',
      '</Quadrapoliesportiva>'
    );
    $array['BanheiroEmpregada'] = IntegrationService::get_string_between(
      $imovel,
      '<Banheiroempregada>',
      '</Banheiroempregada>'
    );
    $array['DormitorioEmpregada'] = IntegrationService::get_string_between(
      $imovel,
      '<Dormitoriosempregada>',
      '</Dormitoriosempregada>'
    );
    $array['VarandaGourmet'] = IntegrationService::get_string_between($imovel, '<Varandagourmet>', '</Varandagourmet>');
    $array['ArmarioPlanCozinha'] = IntegrationService::get_string_between(
      $imovel,
      '<Armariocozinha>',
      '</Armariocozinha>'
    );
    $array['ArmarioPlanQuartos'] = IntegrationService::get_string_between(
      $imovel,
      '<Armariodormitorio>',
      '</Armariodormitorio>'
    );
    $array['QuadraSquash'] = IntegrationService::get_string_between($imovel, '<Quadrasquash>', '</Quadrasquash>');
    $array['CampoFutebol'] = IntegrationService::get_string_between($imovel, '<Campofutebol>', '</Campofutebol>');
    $array['SaunaUmida'] = IntegrationService::get_string_between($imovel, '<Sauna>', '</Sauna>');
    $array['Brinquedoteca'] = IntegrationService::get_string_between($imovel, '<Brinquedoteca>', '</Brinquedoteca>');
    $array['FrenteMar'] = IntegrationService::get_string_between($imovel, '<Frentemar>', '</Frentemar>');
    $array['VistaMar'] = IntegrationService::get_string_between($imovel, '<Vistamar>', '</Vistamar>');
    $array['Bicicletario'] = IntegrationService::get_string_between($imovel, '<Biciletario>', '</Biciletario>');
    $array['Gerador'] = IntegrationService::get_string_between($imovel, '<Gerador>', '</Gerador>');
    $array['Portaria24'] = IntegrationService::get_string_between($imovel, '<Portaria24horas>', '</Portaria24horas>');
    $array['GasEncanado'] = IntegrationService::get_string_between($imovel, '<Gas>', '</Gas>');
    $array['SalaoJogos'] = IntegrationService::get_string_between($imovel, '<Salajogos>', '</Salajogos>');
    $array['Varanda'] = IntegrationService::get_string_between($imovel, '<Varanda>', '</Varanda>');

    $array['UF'] = IntegrationService::get_string_between($imovel, '<UnidadeFederativa>', '</UnidadeFederativa>');
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<Cidade>', '</Cidade>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<Bairro>', '</Bairro>');
    $array['BairroComercial'] = IntegrationService::get_string_between(
      $imovel,
      '<Bairrocomercial>',
      '</Bairrocomercial>'
    );
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<CEP>', '</CEP>');
    $array['Endereco'] = IntegrationService::get_string_between($imovel, '<Endereco>', '</Endereco>');
    $array['Complemento'] = IntegrationService::get_string_between($imovel, '<Complemento>', '</Complemento>');
    $array['Numero'] = IntegrationService::get_string_between($imovel, '<Numero>', '</Numero>');
    $array['Latitude'] = IntegrationService::get_string_between($imovel, '<Latitude>', '</Latitude>');
    $array['Longitude'] = IntegrationService::get_string_between($imovel, '<Longitude>', '</Longitude>');

    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = null;
    }
    if (!isset($array['Novo'])) {
      $array['Novo'] = null;
    }
    if (!isset($array['UnidadesAndar'])) {
      $array['UnidadesAndar'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }

    $array['Video'] = IntegrationService::get_string_between($imovel, '<LinkVideo>', '</LinkVideo>');
    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<Fotos>', '</Fotos>');
    $foto = explode('<Foto>', $imovel);
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }

      $fotos['name'] = IntegrationService::get_string_between($f, '<URL>', '</URL>');

      $fotos['Principal'] = IntegrationService::get_string_between($f, '<Principal>', '</Principal>');
      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataCreci($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<reference_code>', '</reference_code>');
    $array['Descricao'] = trim(IntegrationService::get_string_between($imovel, '<obs>', '</obs>'));

    $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<sell_price>', '</sell_price>');
    if (!empty($array['PrecoVenda'])) {
      $array['PrecoVenda'] = substr($array['PrecoVenda'], 0, strrpos($array['PrecoVenda'], '.'));
    }
    $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<rent_price>', '</rent_price>');
    if (!empty($array['PrecoLocacao'])) {
      $array['PrecoLocacao'] = substr($array['PrecoLocacao'], 0, strrpos($array['PrecoLocacao'], '.'));
    }
    $venda = IntegrationService::get_string_between($imovel, '<sell_available>', '</sell_available>');
    $locacao = IntegrationService::get_string_between($imovel, '<rent_available>', '</rent_available>');
    if ($venda > 0 && $locacao > 0) {
      $array['TipoOferta'] = 3;
    } elseif ($venda > 0) {
      $array['TipoOferta'] = 1;
    } elseif ($locacao > 0) {
      $array['TipoOferta'] = 2;
    } else {
      if (!$log) {
        dd("ERRO Tipo de Oferta, Creci\nCodigo do Anuncio: " . $array['CodigoImovel']);
      } else {
        fwrite($log, "ERRO Tipo de Oferta, Creci\nCodigo do Anuncio: " . $array['CodigoImovel']);
      }
      return false;
    }

    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<master_type>', '</master_type>');
    switch ($array['TipoImovel']) {
      case 'M-1':
        $array['TipoImovel'] = 'Apartamento';
        break;
      case 'M-2':
        $array['TipoImovel'] = 'Casa';
        break;
      case 'M-3':
        $array['TipoImovel'] = 'Comércio';
        break;
      case 'M-4':
        $array['TipoImovel'] = 'Terreno';
        break;
      case 'M-5':
        $array['TipoImovel'] = 'Rural';
        break;
      case 'M-6':
        $array['TipoImovel'] = 'Industria';
        break;
      default:
        $array['TipoImovel'] = 'Imóvel';
        break;
    }
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<useful_area>', '</useful_area>');
    if (!empty($array['AreaUtil'])) {
      if (stristr($array['AreaUtil'], ',') == true) {
        $array['AreaUtil'] = substr($array['AreaUtil'], 0, strrpos($array['AreaUtil'], ','));
      }
    }
    $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<total_area>', '</total_area>');
    if (!empty($array['AreaTotal'])) {
      if (stristr($array['AreaTotal'], ',') == true) {
        $array['AreaTotal'] = substr($array['AreaTotal'], 0, strrpos($array['AreaTotal'], ','));
      }
    }
    $composto = IntegrationService::get_string_between($imovel, '<composition>', '</composition>');
    $array['QtdDormitorios'] = IntegrationService::get_string_between($composto, '<bedroom>', '</bedroom>');
    $array['QtdSuites'] = IntegrationService::get_string_between($composto, '<suite>', '</suite>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($composto, '<bathroom>', '</bathroom>');
    $array['QtdVagas'] = IntegrationService::get_string_between($composto, '<vagancy>', '</vagancy>');

    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<city>', '</city>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<neighborhood>', '</neighborhood>');
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<zipcode>', '</zipcode>');

    if (!isset($array['AreaTerreno'])) {
      $array['AreaTerreno'] = null;
    }
    if (!isset($array['AreaConstruida'])) {
      $array['AreaConstruida'] = null;
    }
    if (!isset($array['ValorIPTU'])) {
      $array['ValorIPTU'] = null;
    }
    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['Subtitle'])) {
      $array['Subtitle'] = null;
    }
    if (!isset($array['Permuta'])) {
      $array['Permuta'] = 0;
    }
    if (!isset($array['UF'])) {
      $array['UF'] = null;
    }
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    if (!isset($array['Endereco'])) {
      $array['Endereco'] = null;
    }
    if (!isset($array['Numero'])) {
      $array['Numero'] = null;
    }
    if (!isset($array['Latitude'])) {
      $array['Latitude'] = null;
    }
    if (!isset($array['Longitude'])) {
      $array['Longitude'] = null;
    }
    if (!isset($array['Video'])) {
      $array['Video'] = null;
    }
    if (!isset($array['Complemento'])) {
      $array['Complemento'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = null;
    }
    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = null;
    }
    if (!isset($array['Novo'])) {
      $array['Novo'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['UnidadesAndar'])) {
      $array['UnidadesAndar'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }

    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<photos>', '</photos>');

    $foto = explode('<photo>', $imovel);
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }

      $fotos['name'] = IntegrationService::get_string_between($f, '<big_file_url>', '</big_file_url>');
      $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<big_file_url>', '</big_file_url>');

      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataTecImob($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<CodigoImovel>', '</CodigoImovel>');
    $array['Descricao'] = IntegrationService::get_string_between($imovel, '<Observacao>', '</Observacao>');
    if (!empty($array['Descricao'])) {
      $array['Descricao'] = IntegrationService::replaceAsc($array['Descricao']);
    }
    $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<Titulo>', '</Titulo>');
    if (empty($array['Subtitle'])) {
      $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<TituloImovel>', '</TituloImovel>');
      if (empty($array['Subtitle'])) {
        $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<TituloAnuncio>', '</TituloAnuncio>');
      }
    }

    $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<PrecoVenda>', '</PrecoVenda>');
    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<AreaUtil>', '</AreaUtil>');
    $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<AreaTotal>', '</AreaTotal>');
    $array['AreaTerreno'] = IntegrationService::get_string_between($imovel, '<AreaDoTerreno>', '</AreaDoTerreno>');

    if (!empty($array['PrecoVenda'])) {
      if (stristr($array['PrecoVenda'], ',') == true) {
        $array['PrecoVenda'] = substr($array['PrecoVenda'], 0, strrpos($array['PrecoVenda'], ','));
        if (stristr($array['PrecoVenda'], '.') == true) {
          $array['PrecoVenda'] = str_replace('.', '', $array['PrecoVenda']);
        }
      } elseif (stristr($array['PrecoVenda'], '.') == true) {
        if (strlen(strrchr($array['PrecoVenda'], '.')) <= 3) {
          $array['PrecoVenda'] = substr($array['PrecoVenda'], 0, strrpos($array['PrecoVenda'], '.'));
        } else {
          $array['PrecoVenda'] = str_replace('.', '', $array['PrecoVenda']);
        }
      }
    }
    $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<PrecoLocacao>', '</PrecoLocacao>');
    if (!empty($array['PrecoLocacao'])) {
      if (stristr($array['PrecoLocacao'], ',') == true) {
        $array['PrecoLocacao'] = substr($array['PrecoLocacao'], 0, strrpos($array['PrecoLocacao'], ','));
        if (stristr($array['PrecoLocacao'], '.') == true) {
          $array['PrecoLocacao'] = str_replace('.', '', $array['PrecoLocacao']);
        }
      } elseif (stristr($array['PrecoLocacao'], '.') == true) {
        if (strlen(strrchr($array['PrecoLocacao'], '.')) <= 3) {
          $array['PrecoLocacao'] = substr($array['PrecoLocacao'], 0, strrpos($array['PrecoLocacao'], '.'));
        } else {
          $array['PrecoLocacao'] = str_replace('.', '', $array['PrecoLocacao']);
        }
      }
    }
    $array['PrecoTemporada'] = IntegrationService::get_string_between(
      $imovel,
      '<PrecoLocacaoTemporada>',
      '</PrecoLocacaoTemporada>'
    );
    if (!empty($array['PrecoTemporada'])) {
      if (stristr($array['PrecoTemporada'], ',') == true) {
        $array['PrecoTemporada'] = substr($array['PrecoTemporada'], 0, strrpos($array['PrecoTemporada'], ','));
      } elseif (stristr($array['PrecoTemporada'], '.') == true) {
        if (strlen(strrchr($array['PrecoTemporada'], '.')) <= 3) {
          $array['PrecoTemporada'] = substr($array['PrecoTemporada'], 0, strrpos($array['PrecoTemporada'], '.'));
        } else {
          $array['PrecoTemporada'] = str_replace('.', '', $array['PrecoTemporada']);
        }
      }
    }
    if ($array['PrecoVenda'] > 0 && $array['PrecoLocacao'] > 0 && $array['PrecoTemporada'] > 0) {
      $array['TipoOferta'] = 5;
    } elseif ($array['PrecoVenda'] > 0) {
      if ($array['PrecoLocacao'] > 0) {
        $array['TipoOferta'] = 3;
      } elseif ($array['PrecoTemporada'] > 0) {
        $array['TipoOferta'] = 6;
      } else {
        $array['TipoOferta'] = 1;
      }
    } elseif ($array['PrecoLocacao'] > 0) {
      if ($array['PrecoTemporada'] > 0) {
        $array['TipoOferta'] = 7;
      } else {
        $array['TipoOferta'] = 2;
      }
    } elseif ($array['PrecoTemporada'] > 0) {
      $array['TipoOferta'] = 4;
    } else {
      if ($array['PrecoVenda'] === '0' && $array['PrecoLocacao'] === '0' && $array['PrecoTemporada'] === '0') {
        $array['TipoOferta'] = 5;
      } elseif ($array['PrecoVenda'] === '0') {
        if ($array['PrecoLocacao'] === '0') {
          $array['TipoOferta'] = 3;
        } elseif ($array['PrecoTemporada'] === '0') {
          $array['TipoOferta'] = 6;
        } else {
          $array['TipoOferta'] = 1;
        }
      } elseif ($array['PrecoLocacao'] === '0') {
        if ($array['PrecoTemporada'] === '0') {
          $array['TipoOferta'] = 7;
        } else {
          $array['TipoOferta'] = 2;
        }
      } elseif ($array['PrecoTemporada'] === '0') {
        $array['TipoOferta'] = 4;
      } else {
        $array['TipoOferta'] = 1;
      }
    }
    $publicaValores = IntegrationService::get_string_between($imovel, '<Publicavalores>', '</Publicavalores>');
    if (!empty($publicaValores) && $publicaValores != 4) {
      switch ($publicaValores) {
        case 2:
          $array['PrecoLocacao'] = '';
          $array['PrecoTemporada'] = '';
          break;
        case 3:
          $array['PrecoVenda'] = '';
          break;
        case 4:
          $array['PrecoVenda'] = '';
          $array['PrecoLocacao'] = '';
          $array['PrecoTemporada'] = '';
          break;
      }
    }
    $array['PrecoCondominio'] = IntegrationService::get_string_between(
      $imovel,
      '<PrecoCondominio>',
      '</PrecoCondominio>'
    );
    $array['ValorIPTU'] = IntegrationService::get_string_between($imovel, '<ValorIPTU>', '</ValorIPTU>');
    if (empty($array['ValorIPTU'])) {
      $array['ValorIPTU'] = trim(
        str_replace("R$", '', IntegrationService::get_string_between($imovel, '<Iptu>', '</Iptu>'))
      );
    }
    if (empty($array['ValorIPTU'])) {
      $array['ValorIPTU'] = trim(
        str_replace(
          "R$",
          '',
          IntegrationService::get_string_between($imovel, '<PrecoIptuImovel>', '</PrecoIptuImovel>')
        )
      );
    }
    if (empty($array['ValorIPTU'])) {
      $array['ValorIPTU'] = trim(
        str_replace("R$", '', IntegrationService::get_string_between($imovel, '<PrecoIptu>', '</PrecoIptu>'))
      );
    }
    if (empty($array['ValorIPTU'])) {
      $array['ValorIPTU'] = trim(
        str_replace("R$", '', IntegrationService::get_string_between($imovel, '<IPTU>', '</IPTU>'))
      );
    }
    $array['Permuta'] = IntegrationService::get_string_between($imovel, '<AceitaPermuta>', '</AceitaPermuta>');
    if ($array['Permuta'] > 0) {
      $array['Permuta'] = 1;
    }
    $garantias = IntegrationService::get_string_between($imovel, '<GarantiaLocacao>', '</GarantiaLocacao>');
    if (!empty($array['GarantiaAluguel'])) {
      if (stristr($garantias, '<Garantia>Fiança empresarial</Garantia>') == true) {
        $array['GarantiaAluguel'] = 6;
      }
      if (stristr($garantias, '<Garantia>Carta Fiança</Garantia>') == true) {
        $array['GarantiaAluguel'] = 3;
      }
      if (stristr($garantias, '<Garantia>Capitalização</Garantia>') == true) {
        $array['GarantiaAluguel'] = 5;
      }
      if (stristr($garantias, '<Garantia>Fiador</Garantia>') == true) {
        $array['GarantiaAluguel'] = 4;
      }
      if (stristr($garantias, '<Garantia>Seguro Fiança</Garantia>') == true) {
        $array['GarantiaAluguel'] = 2;
      }
      if (stristr($garantias, '<Garantia>Caução</Garantia>') == true) {
        $array['GarantiaAluguel'] = 1;
      }
      if (stristr($garantias, '<Garantia>Aluguel antecipado</Garantia>') == true) {
        $array['GarantiaAluguel'] = 1;
      }
    }
    if (empty($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = 0;
    }

    $array['UnidadesAndar'] = IntegrationService::get_string_between($imovel, '<QtdAndar>', '</QtdAndar>');

    $array['AnoConstrucao'] = IntegrationService::get_string_between($imovel, '<AnoConstrucao>', '</AnoConstrucao>');
    $array['Novo'] = IntegrationService::get_string_between($imovel, '<StatusComercial>', '</StatusComercial>');
    if (!empty($array['Novo'])) {
      switch ($array['Novo']) {
        case 'Revenda':
          $array['Novo'] = 0;
          break;
        case 'Padrão':
          $array['Novo'] = 0;
          break;
        case 'Pronto para Morar':
          $array['Novo'] = 1;
          break;
        case 'Lançamento':
          $array['Novo'] = 3;
          break;
        case 'Futuro Lançamento':
          $array['Novo'] = 3;
          break;
        case 'Pré-Lançamento':
          $array['Novo'] = 3;
          break;
        case 'Últimas unidades':
          $array['Novo'] = 3;
          break;
      }
    }
    if (empty($array['Novo'])) {
      $array['Novo'] = 0;
    }
    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<TipoImovel>', '</TipoImovel>');
    $array['SubTipoImovel'] = IntegrationService::get_string_between($imovel, '<SubTipoImovel>', '</SubTipoImovel>');
    if (!empty($array['SubTipoImovel'])) {
      $array['TipoImovel'] = $array['TipoImovel'] . ' ' . $array['SubTipoImovel'];
    }
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<AreaUtil>', '</AreaUtil>');
    if (!empty($array['AreaUtil'])) {
      if (stristr($array['AreaUtil'], ',') == true) {
        $array['AreaUtil'] = substr($array['AreaUtil'], 0, strrpos($array['AreaUtil'], ','));
      }
      if (stristr($array['AreaUtil'], 'm2') == true) {
        $array['AreaUtil'] = str_replace('m2', '', $array['AreaUtil']);
      }
    }
    $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<AreaTotal>', '</AreaTotal>');
    if (!empty($array['AreaTotal'])) {
      if (stristr($array['AreaTotal'], ',') == true) {
        $array['AreaTotal'] = substr($array['AreaTotal'], 0, strrpos($array['AreaTotal'], ','));
      }
    }
    $array['AreaTerreno'] = IntegrationService::get_string_between($imovel, '<AreaDoTerreno>', '</AreaDoTerreno>');
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<QtdDormitorios>', '</QtdDormitorios>');
    $array['QtdSuites'] = IntegrationService::get_string_between($imovel, '<QtdSuites>', '</QtdSuites>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<QtdBanheiros>', '</QtdBanheiros>');
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<QtdVagas>', '</QtdVagas>');

    $array['ArCondicionado'] = IntegrationService::get_string_between($imovel, '<Arcondicionado>', '</Arcondicionado>');
    if (empty($array['ArCondicionado'])) {
      $array['ArCondicionado'] = IntegrationService::get_string_between(
        $imovel,
        '<ArCondicionado>',
        '</ArCondicionado>'
      );
    }
    $array['Piscina'] = IntegrationService::get_string_between($imovel, '<Piscina>', '</Piscina>');
    $array['Churrasqueira'] = IntegrationService::get_string_between($imovel, '<Churrasqueira>', '</Churrasqueira>');
    $array['Academia'] = IntegrationService::get_string_between($imovel, '<Academia>', '</Academia>');
    $array['SalaoFesta'] = IntegrationService::get_string_between($imovel, '<SalaoFestas>', '</SalaoFestas>');
    $array['Playground'] = IntegrationService::get_string_between($imovel, '<Playground>', '</Playground>');
    $array['QuadraPoliesportiva'] = IntegrationService::get_string_between(
      $imovel,
      '<QuadraPoliEsportiva>',
      '</QuadraPoliEsportiva>'
    );
    $array['BanheiroEmpregada'] = IntegrationService::get_string_between(
      $imovel,
      '<QuartoWCEmpregada>',
      '</QuartoWCEmpregada>'
    );
    if (empty($array['BanheiroEmpregada'])) {
      $array['BanheiroEmpregada'] = IntegrationService::get_string_between($imovel, '<WCEmpregada>', '</WCEmpregada>');
    }
    $array['DormitorioEmpregada'] = IntegrationService::get_string_between(
      $imovel,
      '<QuartoWCEmpregada>',
      '</QuartoWCEmpregada>'
    );
    if (empty($array['DormitorioEmpregada'])) {
      $array['DormitorioEmpregada'] = IntegrationService::get_string_between(
        $imovel,
        '<DormitorioEmpregada>',
        '</DormitorioEmpregada>'
      );
    }
    $array['Deposito'] = IntegrationService::get_string_between($imovel, '<Deposito>', '</Deposito>');
    if (empty($array['Deposito'])) {
      $array['Deposito'] = IntegrationService::get_string_between($imovel, '<DepositoSubsolo>', '</DepositoSubsolo>');
    }
    $array['Sacada'] = IntegrationService::get_string_between($imovel, '<Sacada>', '</Sacada>');
    $array['VarandaGourmet'] = IntegrationService::get_string_between($imovel, '<VarandaGourmet>', '</VarandaGourmet>');
    $array['QuadraTenis'] = IntegrationService::get_string_between($imovel, '<QuadraTenis>', '</QuadraTenis>');
    $array['CampoFutebol'] = IntegrationService::get_string_between($imovel, '<CampoFutebol>', '</CampoFutebol>');
    $array['SaunaUmida'] = IntegrationService::get_string_between($imovel, '<Sauna>', '</Sauna>');
    $array['FrenteMar'] = IntegrationService::get_string_between($imovel, '<FrenteMar>', '</FrenteMar>');
    $array['SalaoJogos'] = IntegrationService::get_string_between($imovel, '<SalaoJogos>', '</SalaoJogos>');
    $array['ArmarioPlanQuartos'] = IntegrationService::get_string_between(
      $imovel,
      '<ArmarioDormitorio>',
      '</ArmarioDormitorio>'
    );
    $array['Elevador'] = IntegrationService::get_string_between($imovel, '<QtdElevador>', '</QtdElevador>');
    if ($array['Elevador'] > 0) {
      $array['Elevador'] = 1;
    }

    $array['UF'] = IntegrationService::get_string_between($imovel, '<UF>', '</UF>');
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, '<Estado>', '</Estado>');
    }
    if (empty($array['UF'])) {
      $array['UF'] = IntegrationService::get_string_between($imovel, '<estado>', '</estado>');
    }
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<Cidade>', '</Cidade>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<Bairro>', '</Bairro>');
    if (!empty($array['Bairro'])) {
      $array['Bairro'] = IntegrationService::replaceAsc($array['Bairro']);
    }
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<CEP>', '</CEP>');
    $array['Endereco'] = IntegrationService::get_string_between($imovel, '<Endereco>', '</Endereco>');
    if (!empty($array['Endereco'])) {
      $array['Endereco'] = str_replace(',', '', $array['Endereco']);
    }
    $array['Numero'] = IntegrationService::get_string_between($imovel, '<Numero>', '</Numero>');
    $array['Complemento'] = IntegrationService::get_string_between($imovel, '<Complemento>', '</Complemento>');
    if (empty($array['Complemento'])) {
      $array['Complemento'] = IntegrationService::get_string_between(
        $imovel,
        '<ComplementoEndereco>',
        '</ComplementoEndereco>'
      );
    }
    $array['Latitude'] = IntegrationService::get_string_between($imovel, '<Latitude>', '</Latitude>');
    $array['Longitude'] = IntegrationService::get_string_between($imovel, '<Longitude>', '</Longitude>');

    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['AreaConstruida'])) {
      $array['AreaConstruida'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }

    $array['Video'] = IntegrationService::get_string_between($imovel, '<video>', '</video>');
    if (empty($array['Video'])) {
      $array['Video'] = IntegrationService::get_string_between($imovel, '<LinkVideo>', '</LinkVideo>');
    }
    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);

    $foto = explode('<Foto>', $imovel);
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }
      $fotos['name'] = IntegrationService::get_string_between($f, '<URLArquivo>', '</URLArquivo>');
      if (empty($fotos['name'])) {
        $fotos['name'] = IntegrationService::get_string_between($f, '<UrlArquivo>', '</UrlArquivo>');
      }
      $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<URLArquivo>', '</URLArquivo>');
      if (empty($fotos['URLArquivo'])) {
        $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<UrlArquivo>', '</UrlArquivo>');
      }
      $fotos['Principal'] = IntegrationService::get_string_between($f, '<Principal>', '</Principal>');
      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
      $galeria[$index] = str_replace('>"', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataVista($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<CodigoImovel>', '</CodigoImovel>');
    if (!empty($array['CodigoImovel'])) {
      $array['CodigoImovel'] = utf8_encode($array['CodigoImovel']);
    }
    $array['Descricao'] = IntegrationService::get_string_between($imovel, '<Descricao>', '</Descricao>');
    if (!empty($array['Descricao'])) {
      $array['Descricao'] = utf8_encode($array['Descricao']);
    }
    $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<TituloAnuncio>', '</TituloAnuncio>');
    if (!empty($array['Subtitle'])) {
      $array['Subtitle'] = utf8_encode($array['Subtitle']);
    }

    $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<PrecoVenda>', '</PrecoVenda>');
    $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<PrecoLocacao>', '</PrecoLocacao>');
    $array['PrecoTemporada'] = IntegrationService::get_string_between(
      $imovel,
      '<PrecoLocacaoTemporada>',
      '</PrecoLocacaoTemporada>'
    );
    if ($array['PrecoVenda'] > 0 && $array['PrecoLocacao'] > 0 && $array['PrecoTemporada'] > 0) {
      $array['TipoOferta'] = 5;
    } elseif ($array['PrecoVenda'] > 0) {
      if ($array['PrecoLocacao'] > 0) {
        $array['TipoOferta'] = 3;
      } elseif ($array['PrecoTemporada'] > 0) {
        $array['TipoOferta'] = 6;
      } else {
        $array['TipoOferta'] = 1;
      }
    } elseif ($array['PrecoLocacao'] > 0) {
      if ($array['PrecoTemporada'] > 0) {
        $array['TipoOferta'] = 7;
      } else {
        $array['TipoOferta'] = 2;
      }
    } elseif ($array['PrecoTemporada'] > 0) {
      $array['TipoOferta'] = 4;
    } else {
      if ($array['PrecoVenda'] === '0' && $array['PrecoLocacao'] === '0' && $array['PrecoTemporada'] === '0') {
        $array['TipoOferta'] = 5;
      } elseif ($array['PrecoVenda'] === '0') {
        if ($array['PrecoLocacao'] === '0') {
          $array['TipoOferta'] = 3;
        } elseif ($array['PrecoTemporada'] === '0') {
          $array['TipoOferta'] = 6;
        } else {
          $array['TipoOferta'] = 1;
        }
      } elseif ($array['PrecoLocacao'] === '0') {
        if ($array['PrecoTemporada'] === '0') {
          $array['TipoOferta'] = 7;
        } else {
          $array['TipoOferta'] = 2;
        }
      } elseif ($array['PrecoTemporada'] === '0') {
        $array['TipoOferta'] = 4;
      } else {
        $array['TipoOferta'] = 1;
      }
    }
    $array['PrecoCondominio'] = IntegrationService::get_string_between(
      $imovel,
      '<PrecoCondominio>',
      '</PrecoCondominio>'
    );
    $array['ValorIPTU'] = IntegrationService::get_string_between($imovel, '<ValorIptu>', '</ValorIptu>');

    $array['UnidadesAndar'] = IntegrationService::get_string_between($imovel, '<QtdAndar>', '</QtdAndar>');

    $array['AnoConstrucao'] = IntegrationService::get_string_between($imovel, '<AnoConstrucao>', '</AnoConstrucao>');
    $array['Novo'] = IntegrationService::get_string_between($imovel, '<Situacao>', '</Situacao>');
    if (!empty($array['Novo'])) {
      switch (utf8_encode($array['Novo'])) {
        case 'Pronto':
          $array['Novo'] = 0;
          break;
        case 'Usado':
          $array['Novo'] = 0;
          break;
        case 'USADO':
          $array['Novo'] = 0;
          break;
        case 'Prontos para Morar':
          $array['Novo'] = 0;
          break;
        case 'Novo':
          $array['Novo'] = 1;
          break;
        case 'NOVO':
          $array['Novo'] = 1;
          break;
        case 'Construção':
          $array['Novo'] = 2;
          break;
        case 'CONSTRUÇÃO':
          $array['Novo'] = 2;
          break;
        case 'Em Construção':
        case 'Em construção':
          $array['Novo'] = 2;
          break;
        case 'Na Planta':
          $array['Novo'] = 2;
          break;
        case 'Em Lançamento':
        case 'LANÇAMENTO':
          $array['Novo'] = 3;
          break;
        default:
          if (!$log) {
            dd(
              "ERRO Tipo de Novo, Vista\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Novo:" .
                $array['Novo'] .
                "\n"
            );
          } else {
            fwrite(
              $log,
              "ERRO Tipo de Novo, Vista\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Novo:" .
                $array['Novo'] .
                "\n"
            );
            return false;
          }
          break;
      }
    }
    if (empty($array['Novo'])) {
      $array['Novo'] = 0;
    }
    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<TipoImovel>', '</TipoImovel>');
    if (!empty($array['TipoImovel'])) {
      $array['TipoImovel'] = utf8_encode($array['TipoImovel']);
    }
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<AreaUtil>', '</AreaUtil>');
    $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<AreaTotal>', '</AreaTotal>');
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<QtdDormitorios>', '</QtdDormitorios>');
    $array['QtdSuites'] = IntegrationService::get_string_between($imovel, '<QtdSuites>', '</QtdSuites>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<QtdBanheiros>', '</QtdBanheiros>');
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<QtdVagas>', '</QtdVagas>');

    $array['ArCondicionado'] = IntegrationService::get_string_between($imovel, '<Arcondicionado>', '</Arcondicionado>');
    if ($array['ArCondicionado'] == 'Sim') {
      $array['ArCondicionado'] = 1;
    } else {
      $array['ArCondicionado'] = 0;
    }
    $array['Piscina'] = IntegrationService::get_string_between($imovel, '<Piscina>', '</Piscina>');
    if ($array['Piscina'] == 'Sim') {
      $array['Piscina'] = 1;
    } else {
      $array['Piscina'] = 0;
    }
    $array['Churrasqueira'] = IntegrationService::get_string_between($imovel, '<Churrasqueira>', '</Churrasqueira>');
    if ($array['Churrasqueira'] == 'Sim') {
      $array['Churrasqueira'] = 1;
    } else {
      $array['Churrasqueira'] = 0;
    }
    $array['SalaoFesta'] = IntegrationService::get_string_between($imovel, '<SalaoFestas>', '</SalaoFestas>');
    if ($array['SalaoFesta'] == 'Sim') {
      $array['SalaoFesta'] = 1;
    } else {
      $array['SalaoFesta'] = 0;
    }
    $array['QuadraPoliesportiva'] = IntegrationService::get_string_between(
      $imovel,
      '<QuadraPoliEsportiva>',
      '</QuadraPoliEsportiva>'
    );
    if ($array['QuadraPoliesportiva'] == 'Sim') {
      $array['QuadraPoliesportiva'] = 1;
    } else {
      $array['QuadraPoliesportiva'] = 0;
    }
    $array['BanheiroEmpregada'] = IntegrationService::get_string_between($imovel, '<WCEmpregada>', '</WCEmpregada>');
    if ($array['BanheiroEmpregada'] == 'Sim') {
      $array['BanheiroEmpregada'] = 1;
    } else {
      $array['BanheiroEmpregada'] = 0;
    }
    $array['Varanda'] = IntegrationService::get_string_between($imovel, '<Varanda>', '</Varanda>');
    if ($array['Varanda'] == 'Sim') {
      $array['Varanda'] = 1;
    } else {
      $array['Varanda'] = 0;
    }
    $array['QuadraTenis'] = IntegrationService::get_string_between($imovel, '<QuadraTenis>', '</QuadraTenis>');
    if ($array['QuadraTenis'] == 'Sim') {
      $array['QuadraTenis'] = 1;
    } else {
      $array['QuadraTenis'] = 0;
    }
    $array['SaunaUmida'] = IntegrationService::get_string_between($imovel, '<Sauna>', '</Sauna>');
    if ($array['SaunaUmida'] == 'Sim') {
      $array['SaunaUmida'] = 1;
    } else {
      $array['SaunaUmida'] = 0;
    }
    $array['Playground'] = IntegrationService::get_string_between($imovel, '<Playground>', '</Playground>');
    if ($array['Playground'] == 'Sim') {
      $array['Playground'] = 1;
    } else {
      $array['Playground'] = 0;
    }
    $array['FrenteMar'] = IntegrationService::get_string_between($imovel, '<FrenteMar>', '</FrenteMar>');
    if ($array['FrenteMar'] == 'Sim') {
      $array['FrenteMar'] = 1;
    } else {
      $array['FrenteMar'] = 0;
    }
    $array['SalaoJogos'] = IntegrationService::get_string_between($imovel, '<SalaoJogos>', '</SalaoJogos>');
    if ($array['SalaoJogos'] == 'Sim') {
      $array['SalaoJogos'] = 1;
    } else {
      $array['SalaoJogos'] = 0;
    }
    $array['Elevador'] = IntegrationService::get_string_between($imovel, '<QtdElevador>', '</QtdElevador>');
    if ($array['Elevador'] > 0) {
      $array['Elevador'] = 1;
    } else {
      $array['Elevador'] = 0;
    }

    $array['UF'] = IntegrationService::get_string_between($imovel, '<UF>', '</UF>');
    if (!empty($array['UF'])) {
      $array['UF'] = utf8_encode($array['UF']);
    }
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<Cidade>', '</Cidade>');
    if (!empty($array['Cidade'])) {
      $array['Cidade'] = utf8_encode($array['Cidade']);
    }
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<Bairro>', '</Bairro>');
    if (!empty($array['Bairro'])) {
      $array['Bairro'] = utf8_encode(IntegrationService::replaceAsc($array['Bairro']));
    }
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<CEP>', '</CEP>');
    $array['Endereco'] = IntegrationService::get_string_between($imovel, '<Endereco>', '</Endereco>');
    if (!empty($array['Endereco'])) {
      $array['Endereco'] = utf8_encode($array['Endereco']);
    }
    $array['Numero'] = IntegrationService::get_string_between($imovel, '<EnderecoNumero>', '</EnderecoNumero>');
    if (!empty($array['Numero'])) {
      $array['Numero'] = utf8_encode($array['Numero']);
    }
    $array['Complemento'] = IntegrationService::get_string_between($imovel, '<Complemento>', '</Complemento>');
    if (!empty($array['Complemento'])) {
      $array['Complemento'] = utf8_encode($array['Complemento']);
    }
    $array['Latitude'] = IntegrationService::get_string_between($imovel, '<GMapsLatitude>', '</GMapsLatitude>');
    $array['Longitude'] = IntegrationService::get_string_between($imovel, '<GMapsLongitude>', '</GMapsLongitude>');

    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['AreaConstruida'])) {
      $array['AreaConstruida'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }
    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = 0;
    }
    if (!isset($array['AreaTerreno'])) {
      $array['AreaTerreno'] = null;
    }
    if (!isset($array['Permuta'])) {
      $array['Permuta'] = 0;
    }
    if (!isset($array['Video'])) {
      $array['Video'] = null;
    }

    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<Fotos>', '</Fotos>');
    $foto = explode("<Foto>\n", $foto);
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }
      $fotos['name'] = IntegrationService::get_string_between($f, '<URL>', '</URL>');
      $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<URL>', '</URL>');
      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
      $galeria[$index] = str_replace('>"', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataImobiBrasil($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<ref>', '</ref>');
    if ($array['CodigoImovel'] == '<![CDATA[]]>') {
      $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<id>', '</id>');
    }
    $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<titulo>', '</titulo>');
    $array['Descricao'] = IntegrationService::get_string_between($imovel, '<descricao>', '</descricao>');
    $array['Spotlight'] = IntegrationService::get_string_between($imovel, '<destacado>', '</destacado>');
    if ($array['Spotlight'] == '<![CDATA[ Sim ]]>') {
      $array['Spotlight'] = 1;
    } else {
      $array['Spotlight'] = 0;
    }

    $array['ValorIPTU'] = IntegrationService::get_string_between($imovel, '<valor_iptu>', '</valor_iptu>');
    $array['PrecoCondominio'] = IntegrationService::get_string_between(
      $imovel,
      '<valor_condominio>',
      '</valor_condominio>'
    );
    $array['TipoOferta'] = IntegrationService::get_string_between($imovel, '<transacao>', '</transacao>');
    $array['PrecoVenda'] = null;
    $array['PrecoLocacao'] = null;
    if ($array['TipoOferta'] == '<![CDATA[Venda]]>') {
      $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<valor>', '</valor>');
      $array['TipoOferta'] = 1;
    } else {
      if ($array['TipoOferta'] == '<![CDATA[Locação]]>') {
        $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<valor>', '</valor>');
        $array['TipoOferta'] = 2;
      } else {
        if ($array['TipoOferta'] == '<![CDATA[Temporada]]>') {
          $array['PrecoTemporada'] = IntegrationService::get_string_between($imovel, '<valor>', '</valor>');
          $array['TipoOferta'] = 4;
        } else {
          if (!$log) {
            dd(
              "ERRO Tipo de Oferta, ImobBrasil\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Oferta:" .
                $array['TipoOferta'] .
                "\n"
            );
          } else {
            fwrite(
              $log,
              "ERRO Tipo de Oferta, ImobBrasil\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Oferta:" .
                $array['TipoOferta'] .
                "\n"
            );
          }
          return false;
        }
      }
    }

    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<tipoimovel>', '</tipoimovel>');
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['AreaConstruida'] = IntegrationService::get_string_between(
      $imovel,
      '<area_construida>',
      '</area_construida>'
    );
    if (!empty($array['AreaConstruida'])) {
      $array['AreaConstruida'] = substr($array['AreaConstruida'], 0, strrpos($array['AreaConstruida'], ','));
    }
    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<area_privativa>', '</area_privativa>');
    if (!empty($array['AreaUtil'])) {
      $array['AreaUtil'] = substr($array['AreaUtil'], 0, strrpos($array['AreaUtil'], ','));
    }
    $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<area_total>', '</area_total>');
    $array['AreaTotal'] = str_replace('.', '', $array['AreaTotal']);
    if (!empty($array['AreaTotal'])) {
      $array['AreaTotal'] = substr($array['AreaTotal'], 0, strrpos($array['AreaTotal'], ','));
    }
    $array['AreaTerreno'] = IntegrationService::get_string_between($imovel, '<area_terreno>', '</area_terreno>');
    if (!empty($array['AreaTerreno'])) {
      $array['AreaTerreno'] = substr($array['AreaTerreno'], 0, strrpos($array['AreaTerreno'], ','));
    }
    $array['AnoConstrucao'] = IntegrationService::get_string_between($imovel, '<ano_construcao>', '</ano_construcao>');
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<dormitorios>', '</dormitorios>');
    $array['QtdSuites'] = IntegrationService::get_string_between($imovel, '<suites>', '</suites>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<banheiro>', '</banheiro>');
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<vagas>', '</vagas>');

    $array['UF'] = IntegrationService::get_string_between($imovel, '<endereco_estado>', '</endereco_estado>');
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<endereco_cidade>', '</endereco_cidade>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<endereco_bairro>', '</endereco_bairro>');
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<endereco_cep>', '</endereco_cep>');
    $array['Endereco'] = IntegrationService::get_string_between(
      $imovel,
      '<endereco_logradouro>',
      '</endereco_logradouro>'
    );
    $array['Complemento'] = IntegrationService::get_string_between(
      $imovel,
      '<endereco_complemento>',
      '</endereco_complemento>'
    );
    $array['Numero'] = IntegrationService::get_string_between($imovel, '<endereco_numero>', '</endereco_numero>');

    if (!isset($array['Permuta'])) {
      $array['Permuta'] = 0;
    }
    if (!isset($array['Latitude'])) {
      $array['Latitude'] = null;
    }
    if (!isset($array['Longitude'])) {
      $array['Longitude'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = null;
    }
    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = null;
    }
    if (!isset($array['Novo'])) {
      $array['Novo'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['UnidadesAndar'])) {
      $array['UnidadesAndar'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }

    $array['Video'] = IntegrationService::get_string_between($imovel, '<video>', '</video>');
    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<fotos>', '</fotos>');

    $foto = explode('<foto>', $imovel);
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }

      $fotos['name'] = IntegrationService::get_string_between($f, '<foto_url>', '</foto_url>');
      $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<foto_url>', '</foto_url>');
      $fotos['Principal'] = IntegrationService::get_string_between($f, '<foto_principal>', '</foto_principal>');
      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $g) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataOpenNavegant($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<codigoAnuncio>', '</codigoAnuncio>');
    if (empty($array['CodigoImovel'])) {
      $array['CodigoImovel'] = IntegrationService::get_string_between($imovel, '<CodigoImovel>', '</CodigoImovel>');
    }
    $array['Descricao'] = IntegrationService::get_string_between($imovel, '<descricao>', '</descricao>');
    if (empty($array['Descricao'])) {
      $array['Descricao'] = IntegrationService::get_string_between($imovel, '<Observacao>', '</Observacao>');
    }
    $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<Titulo>', '</Titulo>');
    if (empty($array['Subtitle'])) {
      $array['Subtitle'] = IntegrationService::get_string_between($imovel, '<TituloImovel>', '</TituloImovel>');
    }

    $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<PrecoVenda>', '</PrecoVenda>');
    if (empty($array['PrecoVenda'])) {
      $array['PrecoVenda'] = null;
    }
    $array['PrecoLocacao'] = null;
    $array['PrecoTemporada'] = null;
    if ($array['PrecoVenda'] === null && $array['PrecoLocacao'] === null && $array['PrecoTemporada'] === null) {
      $tansactionData = IntegrationService::get_string_between($imovel, '<precos>', '</precos>');
      foreach (explode('<preco>', $tansactionData) as $item) {
        if (stristr($item, '<operacao>VENTA</operacao>') == true) {
          $array['PrecoVenda'] = IntegrationService::get_string_between($item, '<quantidade>', '</quantidade>');
        }
      }
    }
    $array['PrecoCondominio'] = IntegrationService::get_string_between(
      $imovel,
      '<PrecoCondominio>',
      '</PrecoCondominio>'
    );

    if ($array['PrecoVenda'] > 0 && $array['PrecoLocacao'] > 0 && $array['PrecoTemporada'] > 0) {
      $array['TipoOferta'] = 5;
    } elseif ($array['PrecoVenda'] > 0) {
      if ($array['PrecoLocacao'] > 0) {
        $array['TipoOferta'] = 3;
      } elseif ($array['PrecoTemporada'] > 0) {
        $array['TipoOferta'] = 6;
      } else {
        $array['TipoOferta'] = 1;
      }
    } elseif ($array['PrecoLocacao'] > 0) {
      if ($array['PrecoTemporada'] > 0) {
        $array['TipoOferta'] = 7;
      } else {
        $array['TipoOferta'] = 2;
      }
    } elseif ($array['PrecoTemporada'] > 0) {
      $array['TipoOferta'] = 4;
    } else {
      if ($array['PrecoVenda'] === '0' && $array['PrecoLocacao'] === '0' && $array['PrecoTemporada'] === '0') {
        $array['TipoOferta'] = 5;
      } elseif ($array['PrecoVenda'] === '0') {
        if ($array['PrecoLocacao'] === '0') {
          $array['TipoOferta'] = 3;
        } elseif ($array['PrecoTemporada'] === '0') {
          $array['TipoOferta'] = 6;
        } else {
          $array['TipoOferta'] = 1;
        }
      } elseif ($array['PrecoLocacao'] === '0') {
        if ($array['PrecoTemporada'] === '0') {
          $array['TipoOferta'] = 7;
        } else {
          $array['TipoOferta'] = 2;
        }
      } elseif ($array['PrecoTemporada'] === '0') {
        $array['TipoOferta'] = 4;
      } else {
        $array['TipoOferta'] = 1;
      }
    }
    $array['Permuta'] = IntegrationService::get_string_between($imovel, '<AceitaPermuta>', '</AceitaPermuta>');

    $array['AreaUtil'] = IntegrationService::get_string_between($imovel, '<AreaUtil>', '</AreaUtil>');
    $array['AreaTotal'] = IntegrationService::get_string_between($imovel, '<AreaTotal>', '</AreaTotal>');
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<QtdDormitorios>', '</QtdDormitorios>');
    $array['QtdSuites'] = IntegrationService::get_string_between($imovel, '<QtdSuites>', '</QtdSuites>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<QtdBanheiros>', '</QtdBanheiros>');
    $array['QtdVagas'] = IntegrationService::get_string_between($imovel, '<QtdVagas>', '</QtdVagas>');
    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<tipo>', '</tipo>');
    if (empty($array['TipoImovel'])) {
      $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<TipoImovel>', '</TipoImovel>');
    }
    $array['SubTipoImovel'] = IntegrationService::get_string_between($imovel, '<subTipo>', '</subTipo>');
    if (empty($array['SubTipoImovel'])) {
      $array['SubTipoImovel'] = IntegrationService::get_string_between($imovel, '<SubTipoImovel>', '</SubTipoImovel>');
    }
    if (!empty($array['SubTipoImovel'])) {
      $array['TipoImovel'] = $array['TipoImovel'] . ' ' . $array['SubTipoImovel'];
    }
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['Novo'] = IntegrationService::get_string_between($imovel, '<ProntoMorar>', '</ProntoMorar>');
    if (!empty($array['Novo'])) {
      switch ($array['Novo']) {
        case 0:
          $array['Novo'] = 2;
          break;
        case 1:
          $array['Novo'] = 0;
          break;
        default:
          if (!$log) {
            dd(
              "ERRO Tipo de Novo, OpenNavegant\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Novo:" .
                $array['Novo'] .
                "\n"
            );
          } else {
            fwrite(
              $log,
              "ERRO Tipo de Novo, OpenNavegant\nCodigo do Anuncio: " .
                $array['CodigoImovel'] .
                "\nTipo de Novo:" .
                $array['Novo'] .
                "\n"
            );
            return false;
          }
          break;
      }
    }
    if (empty($array['Novo'])) {
      $array['Novo'] = 0;
    }
    $array['FrenteMar'] = IntegrationService::get_string_between($imovel, '<FrenteMar>', '</FrenteMar>');
    $array['Academia'] = IntegrationService::get_string_between($imovel, '<Academia>', '</Academia>');
    $array['ArCondicionado'] = IntegrationService::get_string_between($imovel, '<ArCondicionado>', '</ArCondicionado>');
    $array['Churrasqueira'] = IntegrationService::get_string_between($imovel, '<Churrasqueira>', '</Churrasqueira>');
    $array['Playground'] = IntegrationService::get_string_between($imovel, '<Playground>', '</Playground>');
    $array['SalaoFesta'] = IntegrationService::get_string_between($imovel, '<SalaoFestas>', '</SalaoFestas>');
    $array['SaunaUmida'] = IntegrationService::get_string_between($imovel, '<Sauna>', '</Sauna>');
    $array['Piscina'] = IntegrationService::get_string_between($imovel, '<Piscina>', '</Piscina>');
    $array['CampoFutebol'] = IntegrationService::get_string_between($imovel, '<CampoFutebol>', '</CampoFutebol>');
    $array['QuadraPoliesportiva'] = IntegrationService::get_string_between(
      $imovel,
      '<QuadraPoliEsportiva>',
      '</QuadraPoliEsportiva>'
    );
    $datasForProperty = IntegrationService::get_string_between($imovel, '<caracteristicas>', '</caracteristicas>');
    foreach (explode('<caracteristica>', $datasForProperty) as $item) {
      if (stristr($item, '<nome>MEDIDAS|AREA_UTIL</nome>') == true) {
        $array['AreaUtil'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>MEDIDAS|AREA_TOTAL</nome>') == true) {
        $array['AreaTotal'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>PRINCIPALES|CONDOMINIO</nome>') == true) {
        $array['PrecoCondominio'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>PRINCIPALES|IPTU</nome>') == true) {
        $array['ValorIPTU'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>AREA_PRIVATIVA|ESTUDA_PERMUTA</nome>') == true) {
        $array['Permuta'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>PRINCIPALES|IDADE_DO_IMOVEL</nome>') == true) {
        $array['AnoConstrucao'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
        if (!empty($array['AnoConstrucao'])) {
          $array['AnoConstrucao'] = Carbon::now()->subYears($array['AnoConstrucao'])->format('Y');
        }
      }
      if (stristr($item, '<nome>PRINCIPALES|QUARTO</nome>') == true) {
        $array['QtdDormitorios'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>PRINCIPALES|SUITE</nome>') == true) {
        $array['QtdSuites'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>PRINCIPALES|BANHEIRO</nome>') == true) {
        $array['QtdBanheiros'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
      if (stristr($item, '<nome>PRINCIPALES|VAGA</nome>') == true) {
        $array['QtdVagas'] = IntegrationService::get_string_between($item, '<valor>', '</valor>');
      }
    }

    $array['UF'] = IntegrationService::get_string_between($imovel, '<UF>', '</UF>');
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    if ($array['UF'] == 'SKIP') {
      return false;
    }
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<Cidade>', '</Cidade>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<Bairro>', '</Bairro>');
    $array['CEP'] = IntegrationService::get_string_between($imovel, '<codigoPostal>', '</codigoPostal>');
    if (empty($array['CEP'])) {
      $array['CEP'] = IntegrationService::get_string_between($imovel, '<CEP>', '</CEP>');
    }
    $array['Endereco'] = IntegrationService::get_string_between($imovel, '<endereco>', '</endereco>');
    if (empty($array['Endereco'])) {
      $array['Endereco'] = IntegrationService::get_string_between($imovel, '<Endereco>', '</Endereco>');
    }
    $array['Latitude'] = IntegrationService::get_string_between($imovel, '<latitude>', '</latitude>');
    if (empty($array['Latitude'])) {
      $array['Latitude'] = IntegrationService::get_string_between($imovel, '<Latitude>', '</Latitude>');
    }
    $array['Longitude'] = IntegrationService::get_string_between($imovel, '<longitude>', '</longitude>');
    if (empty($array['Longitude'])) {
      $array['Longitude'] = IntegrationService::get_string_between($imovel, '<Longitude>', '</Longitude>');
    }

    if (!isset($array['AreaTerreno'])) {
      $array['AreaTerreno'] = null;
    }
    if (!isset($array['AreaConstruida'])) {
      $array['AreaConstruida'] = null;
    }
    if (!isset($array['ValorIPTU'])) {
      $array['ValorIPTU'] = null;
    }
    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['Numero'])) {
      $array['Numero'] = null;
    }
    if (!isset($array['Video'])) {
      $array['Video'] = null;
    }
    if (!isset($array['Complemento'])) {
      $array['Complemento'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = null;
    }
    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = null;
    }
    if (!isset($array['Novo'])) {
      $array['Novo'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['UnidadesAndar'])) {
      $array['UnidadesAndar'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }

    $array['Video'] = IntegrationService::get_string_between($imovel, '<codigoVideo>', '</codigoVideo>');
    if (empty($array['Video'])) {
      $video = IntegrationService::get_string_between($imovel, '<Video>', '</Video>');
      $array['Video'] = IntegrationService::get_string_between($video, '<Url>', '</Url>');
    }
    if (!empty($array['Video']) && stristr($array['Video'], 'www.youtube') == false) {
      $array['Video'] = 'https://www.youtube.com/watch?v=' . $array['Video'];
    }
    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<imagens>', '</imagens>');
    if (empty($foto)) {
      $foto = IntegrationService::get_string_between($imovel, '<Fotos>', '</Fotos>');
    }
    if (stristr($foto, '<Foto>') == true) {
      $foto = explode('<Foto>', $foto);
    } elseif (stristr($foto, '<imagem>') == true) {
      $foto = explode('<imagem>', $foto);
    }
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }
      $fotos['name'] = IntegrationService::get_string_between($f, '<urlImagem>', '</urlImagem>');
      $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<urlImagem>', '</urlImagem>');
      if (empty($fotos['name'])) {
        $fotos['name'] = IntegrationService::get_string_between($f, '<URLArquivo>', '</URLArquivo>');
        $fotos['URLArquivo'] = IntegrationService::get_string_between($f, '<URLArquivo>', '</URLArquivo>');
        $fotos['Principal'] = IntegrationService::get_string_between($f, '<Principal>', '</Principal>');
      }
      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
      $galeria[$index] = str_replace('>"', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function imovelDataMigMidia($imovel, $log)
  {
    $array = [];
    $array['CodigoImovel'] = trim(IntegrationService::get_string_between($imovel, '<id>', '</id>'));
    $array['TipoImovel'] = IntegrationService::get_string_between($imovel, '<property_type>', '</property_type>');
    $array['TipoImovel'] = IntegrationService::tipoImovel($array['TipoImovel'], $log);
    $array['Descricao'] = trim(IntegrationService::get_string_between($imovel, '<content>', '</content>'));
    if (!empty($array['Descricao'])) {
      $array['Descricao'] = IntegrationService::replaceAsc($array['Descricao']);
    }

    $array['TipoOferta'] = IntegrationService::get_string_between($imovel, '<type>', '</type>');
    $array['PrecoVenda'] = null;
    $array['PrecoLocacao'] = null;
    if ($array['TipoOferta'] == '<![CDATA[For Sale]]>') {
      $array['PrecoVenda'] = IntegrationService::get_string_between($imovel, '<price>', '</price>');
      $array['TipoOferta'] = 1;
    } elseif ($array['TipoOferta'] == '<![CDATA[For Rent]]>') {
      $array['PrecoLocacao'] = IntegrationService::get_string_between($imovel, '<price>', '</price>');
      $array['TipoOferta'] = 2;
    } else {
      if (!$log) {
        dd(
          "ERRO Tipo de Oferta, ImobIO\nCodigo do Anuncio: " .
            $array['CodigoImovel'] .
            "\nTipo de Oferta:" .
            $array['TipoOferta'] .
            "\n"
        );
      } else {
        fwrite(
          $log,
          "ERRO Tipo de Oferta, ImobIO\nCodigo do Anuncio: " .
            $array['CodigoImovel'] .
            "\nTipo de Oferta:" .
            $array['TipoOferta'] .
            "\n"
        );
      }
      return false;
      if ($array['TipoOferta'] == '<![CDATA[Locação]]>') {
      } else {
      }
    }

    $array['AreaUtil'] = IntegrationService::get_string_between(
      $imovel,
      '<floor_area unit="hectares">',
      '</floor_area>'
    );
    if (!empty($array['AreaUtil'])) {
      $array['AreaUtil'] = str_replace('<![CDATA[', '', $array['AreaUtil']);
      $array['AreaUtil'] = str_replace(']]>', '', $array['AreaUtil']);
      $array['AreaUtil'] = str_replace('>"', '', $array['AreaUtil']);
      $array['AreaUtil'] = $array['AreaUtil'] * 10000;
    } else {
      $array['AreaUtil'] = IntegrationService::get_string_between(
        $imovel,
        '<floor_area unit="meters">',
        '</floor_area>'
      );
    }
    $array['QtdDormitorios'] = IntegrationService::get_string_between($imovel, '<rooms>', '</rooms>');
    $array['QtdBanheiros'] = IntegrationService::get_string_between($imovel, '<bathrooms>', '</bathrooms>');

    $array['UF'] = '00';
    $array['UF'] = IntegrationService::fixUf($array['UF'], $log);
    $array['Cidade'] = IntegrationService::get_string_between($imovel, '<city>', '</city>');
    $array['Bairro'] = IntegrationService::get_string_between($imovel, '<region>', '</region>');

    if (!isset($array['Spotlight'])) {
      $array['Spotlight'] = 0;
    }
    if (!isset($array['Subtitle'])) {
      $array['Subtitle'] = null;
    }
    if (!isset($array['AreaTotal'])) {
      $array['AreaTotal'] = null;
    }
    if (!isset($array['AreaUtil'])) {
      $array['AreaUtil'] = 0;
    }
    if (!isset($array['QtdSuites'])) {
      $array['QtdSuites'] = null;
    }
    if (!isset($array['ValorIPTU'])) {
      $array['ValorIPTU'] = null;
    }
    if (!isset($array['CEP'])) {
      $array['CEP'] = 0;
    }
    if (!isset($array['Endereco'])) {
      $array['Endereco'] = '';
    }
    if (!isset($array['Numero'])) {
      $array['Numero'] = null;
    }
    if (!isset($array['AreaConstruida'])) {
      $array['AreaConstruida'] = null;
    }
    if (!isset($array['Permuta'])) {
      $array['Permuta'] = 0;
    }
    if (!isset($array['Latitude'])) {
      $array['Latitude'] = null;
    }
    if (!isset($array['Longitude'])) {
      $array['Longitude'] = null;
    }
    if (!isset($array['PrecoTemporada'])) {
      $array['PrecoTemporada'] = null;
    }
    if (!isset($array['GarantiaAluguel'])) {
      $array['GarantiaAluguel'] = null;
    }
    if (!isset($array['Novo'])) {
      $array['Novo'] = null;
    }
    if (!isset($array['Andares'])) {
      $array['Andares'] = null;
    }
    if (!isset($array['UnidadesAndar'])) {
      $array['UnidadesAndar'] = null;
    }
    if (!isset($array['Torres'])) {
      $array['Torres'] = null;
    }
    if (!isset($array['Video'])) {
      $array['Video'] = null;
    }
    if (!isset($array['BairroComercial'])) {
      $array['BairroComercial'] = null;
    }
    if (!isset($array['Complemento'])) {
      $array['Complemento'] = null;
    }
    if (!isset($array['MostrarEndereco'])) {
      $array['MostrarEndereco'] = 2;
    }
    if (!isset($array['AreaTerreno'])) {
      $array['AreaTerreno'] = null;
    }
    if (!isset($array['QtdVagas'])) {
      $array['QtdVagas'] = 0;
    }

    foreach ($array as $index => $a) {
      if (!empty($array[$index])) {
        $array[$index] = str_replace('<![CDATA[', '', $array[$index]);
        $array[$index] = str_replace(']]>', '', $array[$index]);
      }
    }
    $dados = collect($array);
    $foto = IntegrationService::get_string_between($imovel, '<pictures>', '</pictures>');
    $foto = str_replace("\n", '', $foto);
    $foto = explode('<picture>', $foto);
    foreach ($foto as $index => $f) {
      $foto[$index] = IntegrationService::get_string_between($f, '<picture_url>', '</picture_url>');
    }
    $fotos = [];
    $galeria = [];
    foreach ($foto as $index => $f) {
      if ($index == 0) {
        continue;
      }
      $fotos['name'] = trim($f);

      array_push($galeria, $fotos);
    }
    foreach ($galeria as $index => $a) {
      $galeria[$index] = str_replace('<![CDATA[', '', $galeria[$index]);
      $galeria[$index] = str_replace(']]>', '', $galeria[$index]);
      $galeria[$index] = str_replace('>"', '', $galeria[$index]);
    }
    $dados->fotos = $galeria;
    return $dados;
  }

  public static function getIngaiaTokenCliente($imovel, $user_id, $system)
  {
    $token = IntegrationService::get_string_between($imovel, '<TokenCliente>', '</TokenCliente>');

    if (!DB::table('integration_tokens')->where('user_id', $user_id)->first()) {
      DB::table('integration_tokens')->insert([
        'user_id' => $user_id,
        'system' => $system,
        'token' => $token,
      ]);
    } else {
      DB::table('integration_tokens')
        ->where('user_id', $user_id)
        ->update([
          'system' => $system,
          'token' => $token,
        ]);
    }
  }

  public static function getAccordousTokenCliente($imovel, $user_id, $system)
  {
    if (!DB::table('integration_tokens')->where('user_id', $user_id)->first()) {
      DB::table('integration_tokens')->insert([
        'user_id' => $user_id,
        'system' => $system,
        'token' => 'Não tem token',
      ]);
    } else {
      DB::table('integration_tokens')
        ->where('user_id', $user_id)
        ->update([
          'system' => $system,
          'token' => 'Não tem token',
        ]);
    }
  }

  public static function storeData($imovelData, $user_id, $type, $log)
  {
    $ads = DB::table('anuncios as A')
      ->select(
        'A.id',
        'A.type_id',
        'A.new_immobile',
        'A.negotiation_id',
        'A.condominio_mes',
        'A.valor',
        'A.valor_aluguel',
        'A.valor_temporada',
        'A.rental_guarantee',
        'A.area_total',
        'A.area_util',
        'A.area_terreno',
        'A.area_construida',
        'A.bedrooms',
        'A.suites',
        'A.bathrooms',
        'A.parking',
        'A.description',
        'A.title',
        'A.iptu',
        'A.spotlight',
        'A.subtitle',
        'A.exchange',
        'A.youtube',
        'E.id as address_id',
        'E.cep',
        'E.cidade',
        'E.slug_cidade',
        'E.uf',
        'E.bairro',
        'E.slug_bairro',
        'E.logradouro',
        'E.numero',
        'E.bairro_comercial',
        'E.complement',
        'E.latitude',
        'E.longitude',
        'A.codigo',
        'E.valid_location',
        'A.xml',
        'E.mostrar_endereco'
      )
      ->join('anuncio_enderecos as E', 'E.anuncio_id', 'A.id')
      ->where('A.user_id', $user_id)
      ->where('A.xml', 1)
      ->orderBy('A.id', 'ASC')
      ->get();
    $qtd = 0;
    $ceps = [];
    $districts = [];
    $cities = [];
    $adIds = [];
    foreach ($imovelData as $index => $D) {
      $ceps[] = $D['CEP'];
      $districts[] = Str::slug($D['Bairro']);
      $cities[] = Str::slug($D['Cidade']);
    }
    foreach ($ads as $item) {
      $adIds[] = $item->id;
    }
    $condominiums = IntegrationService::getPossibleCondominium($ceps);
    unset($ceps);
    $districts = IntegrationService::getPossibleDistricts($cities, $districts);
    unset($cities);
    $condominiumsData = IntegrationService::getPossibleCondominiumsData($adIds);
    $adBenefitsAmount = IntegrationService::getBenefitsAmountForEachAd($adIds);
    foreach ($imovelData as $index => $D) {

      $runIntegration = true;
      $tempArray = array_slice($imovelData, $index + 1);
      foreach ($tempArray as $ta) {
        if ($D['CodigoImovel'] === $ta['CodigoImovel']) {
          $runIntegration = false;
        }
      }

      if ($runIntegration) {
        $qtd++;
        $newAd = false;
        if ($type != 'images') {
          $nome_movel = AnuncioService::nameByTypeCod($D['TipoImovel']);
          $titleImovelGuide = $nome_movel;
          if (
            $D['TipoImovel'] <= 5 ||
            ($D['TipoImovel'] >= 7 && $D['TipoImovel'] <= 9) ||
            $D['TipoImovel'] == 11 ||
            ($D['TipoImovel'] >= 19 && $D['TipoImovel'] <= 22)
          ) {
            if ($D['QtdDormitorios'] == 1) {
              $titleImovelGuide = $titleImovelGuide . ' com ' . $D['QtdDormitorios'] . ' Quarto';
            } elseif ($D['QtdDormitorios'] > 1) {
              $titleImovelGuide = $titleImovelGuide . ' com ' . $D['QtdDormitorios'] . ' Quartos';
            }
          }
          switch ($D['TipoOferta']) {
            case 1:
              $titleImovelGuide = $titleImovelGuide . ' à Venda, ';
              break;
            case 2:
              $titleImovelGuide = $titleImovelGuide . ' para Alugar, ';
              break;
            case 3:
              $titleImovelGuide = $titleImovelGuide . ' à Venda ou Locação, ';
              break;
            case 4:
              $titleImovelGuide = $titleImovelGuide . ' para Temporada, ';
              break;
            case 5:
              $titleImovelGuide = $titleImovelGuide . ' à Venda, Locação ou Temporada, ';
              break;
            case 6:
              $titleImovelGuide = $titleImovelGuide . ' à Venda ou Temporada, ';
              break;
            case 7:
              $titleImovelGuide = $titleImovelGuide . ' para Alugar ou Temporada, ';
              break;
          }

          if (isset($D['AreaUtil'])) {
            $D['AreaUtil'] = str_replace('.', '', $D['AreaUtil']);
            $D['AreaUtil'] = preg_replace('/,.+/', '', $D['AreaUtil']);
          }

          if (isset($D['AreaConstruida'])) {
            $D['AreaConstruida'] = str_replace('.', '', $D['AreaConstruida']);
            $D['AreaConstruida'] = preg_replace('/,.+/', '', $D['AreaConstruida']);
          }

          if (isset($D['AreaTotal'])) {
            $D['AreaTotal'] = str_replace('.', '.', $D['AreaTotal']);
            $D['AreaTotal'] = preg_replace('/,.+/', '', $D['AreaTotal']);
          }

          if (isset($D['AreaTerreno'])) {
            $D['AreaTerreno'] = str_replace('.', '.', $D['AreaTerreno']);
            $D['AreaTerreno'] = preg_replace('/,.+/', '', $D['AreaTerreno']);
          }

          if ($D['AreaUtil'] > 0) {

            $titleImovelGuide = $titleImovelGuide . number_format(trim($D['AreaUtil']), 0, ',', '.') . ' m²';
          } elseif ($D['AreaConstruida'] > 0) {

            $titleImovelGuide = $titleImovelGuide . number_format(trim($D['AreaConstruida']), 0, ',', '.') . ' m²';
          } elseif ($D['AreaTotal'] > 0) {

            $titleImovelGuide = $titleImovelGuide . number_format(trim($D['AreaTotal']), 0, ',', '.') . ' m²';
          } elseif ($D['AreaTerreno'] > 0) {

            $titleImovelGuide = $titleImovelGuide . number_format(trim($D['AreaTerreno']), 0, ',', '.') . ' m²';
          }
          if ($D['Bairro'] != null) {
            $titleImovelGuide = $titleImovelGuide . ' em ' . ucwords(mb_strtolower($D['Bairro']));
            if ($D['Cidade'] != null) {
              $titleImovelGuide = $titleImovelGuide . ' - ' . ucwords(mb_strtolower($D['Cidade']));
            }
          } elseif ($D['Cidade'] != null) {
            $titleImovelGuide = $titleImovelGuide . ' em ' . ucwords(mb_strtolower($D['Cidade']));
          }
          $slug = Str::slug($titleImovelGuide);
          if (isset($D['PrecoCondominio'])) {
            $precoCondominio = $D['PrecoCondominio'];
          } else {
            $precoCondominio = null;
          }
          if (!empty($D['TipoOferta'])) {
            $tipoOferta = $D['TipoOferta'];
          } else {
            if (!empty($D['PrecoLocacao'])) {
              $tipoOferta = 2;
            }
            if (!empty($D['PrecoVenda'])) {
              $tipoOferta = 1;
            }
          }
          if (empty($tipoOferta)) {

            if (!$log) {
              dd("ERRO PREÇO\nCodigo do Anuncio: " . $array['CodigoImovel'] . "\n" . $D['PrecoVenda'] . "\n");
            } else {
              fwrite($log, "ERRO PREÇO\nCodigo do Anuncio: " . $array['CodigoImovel'] . "\n" . $D['PrecoVenda'] . "\n");
            }
            return false;
          }
          $D['PrecoVenda'] = str_replace('.', '', $D['PrecoVenda'] ?? '');
          $D['PrecoVenda'] = intval($D['PrecoVenda']);
          $D['PrecoLocacao'] = str_replace('.', '', $D['PrecoLocacao'] ?? '');
          $D['PrecoLocacao'] = intval($D['PrecoLocacao']);
          $D['PrecoTemporada'] = str_replace('.', '', $D['PrecoTemporada'] ?? '');
          $D['PrecoTemporada'] = intval($D['PrecoTemporada']);
          if (stristr($D['Video'], 'https://www.youtube.com/watch?v=') == false) {
            $D['Video'] = null;
          }
          $dados = [
            'user_id' => $user_id,
            'status' => 'ativado',
            'type_id' => $D['TipoImovel'],
            'new_immobile' => $D['Novo'],
            'negotiation_id' => $tipoOferta,
            'condominio_mes' => $precoCondominio,
            'valor' => $D['PrecoVenda'],
            'valor_aluguel' => $D['PrecoLocacao'],
            'valor_temporada' => $D['PrecoTemporada'],
            'rental_guarantee' => $D['GarantiaAluguel'],
            'area_total' => $D['AreaTotal'],
            'area_util' => $D['AreaUtil'],
            'area_terreno' => $D['AreaTerreno'],
            'area_construida' => $D['AreaConstruida'],
            'bedrooms' => $D['QtdDormitorios'],
            'suites' => $D['QtdSuites'],
            'bathrooms' => $D['QtdBanheiros'],
            'codigo' => $D['CodigoImovel'],
            'parking' => $D['QtdVagas'],
            'description' => $D['Descricao'],
            'slug' => $slug,
            'title' => $titleImovelGuide,
            'status' => 'ativado',
            'usage_type_id' => 1,
            'iptu' => $D['ValorIPTU'],
            'xml' => 1,
            'spotlight' => $D['Spotlight'],
            'subtitle' => $D['Subtitle'],
            'exchange' => $D['Permuta'],
            'youtube' => $D['Video'],
          ];
          if ($dados['valor'] > 0 && $dados['area_util'] > 0) {
            $dados['valor_m2'] = $dados['valor'] / trim($dados['area_util']);
          } else {
            $dados['valor_m2'] = null;
          }
          $D['CEP'] = IntegrationService::fixCEP($D['CEP']);
          $condominium = IntegrationService::searchCondominium(
            $condominiums,
            $D['CEP'],
            ucwords(mb_strtolower($D['Endereco'])),
            $D['Numero']
          );
          if ($condominium) {
            $dados['condominio_id'] = $condominium->id;
          }
          $existe = $ads->whereStrict('codigo', $D['CodigoImovel'])->last();
          if ($existe != null) {
            if (
              $existe->type_id != $dados['type_id'] ||
              $existe->new_immobile != $dados['new_immobile'] ||
              $existe->negotiation_id != $dados['negotiation_id'] ||
              $existe->condominio_mes != $dados['condominio_mes'] ||
              $existe->valor != $dados['valor'] ||
              $existe->valor_aluguel != $dados['valor_aluguel'] ||
              $existe->valor_temporada != $dados['valor_temporada'] ||
              $existe->rental_guarantee != $dados['rental_guarantee'] ||
              $existe->area_total != $dados['area_total'] ||
              $existe->area_util != $dados['area_util'] ||
              $existe->area_terreno != $dados['area_terreno'] ||
              $existe->area_construida != $dados['area_construida'] ||
              $existe->bedrooms != $dados['bedrooms'] ||
              $existe->suites != $dados['suites'] ||
              $existe->bathrooms != $dados['bathrooms'] ||
              $existe->parking != $dados['parking'] ||
              $existe->description != $dados['description'] ||
              $existe->title != $dados['title'] ||
              $existe->iptu != $dados['iptu'] ||
              $existe->spotlight != $dados['spotlight'] ||
              $existe->subtitle != $dados['subtitle'] ||
              $existe->exchange != $dados['exchange'] ||
              $existe->youtube != $dados['youtube'] ||
              $existe->xml != 1
            ) {
              $dados['updated_at'] = Carbon::now('America/Sao_Paulo');
              $r = DB::table('anuncios')
                ->where('codigo', '=', $D['CodigoImovel'])
                ->where('user_id', $user_id)
                ->update($dados);
            }
            $id = $existe->id;
          } else {
            $dados['created_at'] = Carbon::now('America/Sao_Paulo');
            $id = DB::table('anuncios')->insertGetId($dados);
            $newAd = true;
          }

          $idsCaracteristicas = [];
          $builder = null;
          if (!empty($D['Construtora'])) {
            $builder = DB::table('builders')->where('name', $D['Construtora'])->first();
            if ($builder) {
              $builder = $builder->id;
            }
          }
          if ($condominium) {
            $condominiumData = [
              'condominiun_id' => $condominium->id,
              'ad_id' => $id,
              'builder_id' => $builder,
              'number_of_floors' => $D['Andares'],
              'units_per_floor' => $D['UnidadesAndar'],
              'number_of_towers' => $D['Torres'],
              'construction_year' => $D['AnoConstrucao'],
              'terrain_size' => $D['AreaTerreno'],
            ];
            $existeCondo = $condominiumsData->where('ad_id', $id)->first();
            if ($existeCondo) {
              if (
                $existeCondo->condominiun_id != $condominiumData['condominiun_id'] ||
                $existeCondo->builder_id != $condominiumData['builder_id'] ||
                $existeCondo->number_of_floors != $condominiumData['number_of_floors'] ||
                $existeCondo->units_per_floor != $condominiumData['units_per_floor'] ||
                $existeCondo->number_of_towers != $condominiumData['number_of_towers'] ||
                $existeCondo->construction_year != $condominiumData['construction_year'] ||
                $existeCondo->terrain_size != $condominiumData['terrain_size']
              ) {
                $r = DB::table('condominium_data')->where('ad_id', $id)->update($condominiumData);
              }
            } else {
              DB::table('condominium_data')->insert($condominiumData);
            }
          }
          if ($D['TipoImovel'] == 1 || $D['TipoImovel'] == 5 || $D['TipoImovel'] == 8 || $D['TipoImovel'] == 9) {
            if (!empty($D['Seguranca24'])) {
              array_push($idsCaracteristicas, 12);
            }
            if (!empty($D['Churrasqueira'])) {
              array_push($idsCaracteristicas, 13);
            }
            if (!empty($D['Elevador'])) {
              array_push($idsCaracteristicas, 14);
            }
            if (!empty($D['Academia'])) {
              array_push($idsCaracteristicas, 17);
            }
            if (!empty($D['SalaoFesta'])) {
              array_push($idsCaracteristicas, 18);
            }
            if (!empty($D['Playground'])) {
              array_push($idsCaracteristicas, 19);
            }
            if (!empty($D['Piscina'])) {
              array_push($idsCaracteristicas, 20);
            }
            if (!empty($D['QuadraPoliesportiva'])) {
              array_push($idsCaracteristicas, 21);
            }
            if (!empty($D['PiscinaAdultoCoberta'])) {
              array_push($idsCaracteristicas, 30);
            }
            if (!empty($D['PiscinaInfantilAberta'])) {
              array_push($idsCaracteristicas, 31);
            }
            if (!empty($D['PiscinaInfantilCoberta'])) {
              array_push($idsCaracteristicas, 32);
            }
            if (!empty($D['QuadraTenis'])) {
              array_push($idsCaracteristicas, 33);
            }
            if (!empty($D['QuadraSquash'])) {
              array_push($idsCaracteristicas, 34);
            }
            if (!empty($D['CampoFutebol'])) {
              array_push($idsCaracteristicas, 35);
            }
            if (!empty($D['CampoGolf'])) {
              array_push($idsCaracteristicas, 36);
            }
            if (!empty($D['SalaoJogos'])) {
              array_push($idsCaracteristicas, 37);
            }
            if (!empty($D['EspacoPet'])) {
              array_push($idsCaracteristicas, 38);
            }
            if (!empty($D['SaunaSeca'])) {
              array_push($idsCaracteristicas, 39);
            }
            if (!empty($D['SaunaUmida'])) {
              array_push($idsCaracteristicas, 40);
            }
            if (!empty($D['Brinquedoteca'])) {
              array_push($idsCaracteristicas, 41);
            }
            if (!empty($D['EstacionaVisitas'])) {
              array_push($idsCaracteristicas, 42);
            }
            if (!empty($D['FrenteMar'])) {
              array_push($idsCaracteristicas, 43);
            }
            if (!empty($D['VistaMar'])) {
              array_push($idsCaracteristicas, 44);
            }
            if (!empty($D['SalaMassagem'])) {
              array_push($idsCaracteristicas, 45);
            }
            if (!empty($D['FornoPizza'])) {
              array_push($idsCaracteristicas, 46);
            }
            if (!empty($D['PortatiraBlindada'])) {
              array_push($idsCaracteristicas, 47);
            }
            if (!empty($D['Bicicletario'])) {
              array_push($idsCaracteristicas, 48);
            }
            if (!empty($D['CarWash'])) {
              array_push($idsCaracteristicas, 49);
            }
            if (!empty($D['Gerador'])) {
              array_push($idsCaracteristicas, 50);
            }
            if (!empty($D['Portaria24'])) {
              array_push($idsCaracteristicas, 51);
            }
            if (!empty($D['GasEncanado'])) {
              array_push($idsCaracteristicas, 52);
            }
          }

          if (
            $D['TipoImovel'] == 1 ||
            $D['TipoImovel'] == 2 ||
            $D['TipoImovel'] == 3 ||
            $D['TipoImovel'] == 4 ||
            $D['TipoImovel'] == 5 ||
            $D['TipoImovel'] == 7 ||
            $D['TipoImovel'] == 8 ||
            $D['TipoImovel'] == 9 ||
            $D['TipoImovel'] == 10 ||
            $D['TipoImovel'] == 12 ||
            $D['TipoImovel'] == 15 ||
            $D['TipoImovel'] == 16 ||
            $D['TipoImovel'] == 18 ||
            $D['TipoImovel'] == 19 ||
            $D['TipoImovel'] == 20 ||
            $D['TipoImovel'] == 21 ||
            $D['TipoImovel'] == 22 ||
            $D['TipoImovel'] == 23
          ) {
            if (!empty($D['ArCondicionado'])) {
              array_push($idsCaracteristicas, 3);
            }
          }

          if ($D['TipoImovel'] == 1 || $D['TipoImovel'] == 8) {
            if (!empty($D['DepositoIndividual'])) {
              array_push($idsCaracteristicas, 53);
            }
          }

          if (
            $D['TipoImovel'] == 1 ||
            $D['TipoImovel'] == 2 ||
            $D['TipoImovel'] == 3 ||
            $D['TipoImovel'] == 4 ||
            $D['TipoImovel'] == 5 ||
            $D['TipoImovel'] == 7 ||
            $D['TipoImovel'] == 8 ||
            $D['TipoImovel'] == 9 ||
            $D['TipoImovel'] == 20 ||
            $D['TipoImovel'] == 21 ||
            $D['TipoImovel'] == 22
          ) {
            if (!empty($D['ArmarioPlanCozinha'])) {
              array_push($idsCaracteristicas, 27);
            }
            if (!empty($D['ArmarioPlanQuartos'])) {
              array_push($idsCaracteristicas, 28);
            }
          }

          if (
            $D['TipoImovel'] == 1 ||
            $D['TipoImovel'] == 2 ||
            $D['TipoImovel'] == 3 ||
            $D['TipoImovel'] == 4 ||
            $D['TipoImovel'] == 7 ||
            $D['TipoImovel'] == 8 ||
            $D['TipoImovel'] == 22
          ) {
            if (!empty($D['BanheiroEmpregada'])) {
              array_push($idsCaracteristicas, 22);
            }
            if (!empty($D['DormitorioEmpregada'])) {
              array_push($idsCaracteristicas, 23);
            }
          }

          if (
            $D['TipoImovel'] == 1 ||
            $D['TipoImovel'] == 5 ||
            $D['TipoImovel'] == 8 ||
            $D['TipoImovel'] == 20 ||
            $D['TipoImovel'] == 21
          ) {
            if (!empty($D['Sacada'])) {
              array_push($idsCaracteristicas, 5);
            }
            if (!empty($D['Deposito'])) {
              array_push($idsCaracteristicas, 6);
            }
            if (!empty($D['VarandaGourmet'])) {
              array_push($idsCaracteristicas, 24);
            }
            if (!empty($D['Varanda'])) {
              array_push($idsCaracteristicas, 25);
            }
            if (!empty($D['VarandaGrill'])) {
              array_push($idsCaracteristicas, 26);
            }
          }

          if ($D['TipoImovel'] == 1 || $D['TipoImovel'] == 8 || $D['TipoImovel'] == 12) {
            if (!empty($D['VarandaTecnica'])) {
              array_push($idsCaracteristicas, 29);
            }
          }
          $amountBenefits = $adBenefitsAmount->where('anuncio_id', $id)->first()
            ? $adBenefitsAmount->where('anuncio_id', $id)->first()->amount
            : 0;
          if (count($idsCaracteristicas) != $amountBenefits) {
            DB::table('anuncio_beneficio')->where('anuncio_id', $id)->delete();
            $caracteristicas = [];
            foreach ($idsCaracteristicas as $c) {
              $caracteristica = [
                'anuncio_id' => $id,
                'beneficio_id' => $c,
              ];
              array_push($caracteristicas, $caracteristica);
            }
            DB::table('anuncio_beneficio')->insert($caracteristicas);
          }
          $slug_cidade = Str::slug($D['Cidade']);
          $slug_bairro = Str::slug($D['Bairro']);

          $end = [
            'anuncio_id' => $id,
            'mostrar_endereco' => $D['MostrarEndereco'],
            'cep' => $D['CEP'],
            'cidade' => ucwords(mb_strtolower($D['Cidade'])),
            'slug_cidade' => $slug_cidade,
            'uf' => $D['UF'],
            'bairro' => ucwords(mb_strtolower($D['Bairro'])),
            'slug_bairro' => $slug_bairro,
            'logradouro' => ucwords(mb_strtolower($D['Endereco'])),
            'numero' => $D['Numero'],
            'bairro_comercial' => ucwords(mb_strtolower($D['BairroComercial'] ?? '')),
            'complement' => $D['Complemento'],
            'latitude' => $D['Latitude'],
            'longitude' => $D['Longitude'],
            'created_at' => Carbon::now()->toDateTimeString(),
          ];

          $valid_location = $districts
            ->where('uf', $D['UF'])
            ->where('slug_cidade', $slug_cidade)
            ->where('slug', $slug_bairro)
            ->first();
          if (!empty($valid_location)) {
            $end['valid_location'] = $valid_location->id;
          } else {
            $end['valid_location'] = 0;
          }

          if ($existe != null) {
            if (
              $existe->mostrar_endereco != $end['mostrar_endereco'] ||
              $existe->cep != $end['cep'] ||
              $existe->cidade != $end['cidade'] ||
              $existe->slug_cidade != $end['slug_cidade'] ||
              $existe->uf != $end['uf'] ||
              $existe->bairro != $end['bairro'] ||
              $existe->slug_bairro != $end['slug_bairro'] ||
              $existe->logradouro != $end['logradouro'] ||
              $existe->numero != $end['numero'] ||
              $existe->bairro_comercial != $end['bairro_comercial'] ||
              $existe->complement != $end['complement'] ||
              $existe->latitude != $end['latitude'] ||
              $existe->longitude != $end['longitude'] ||
              $existe->valid_location != $end['valid_location']
            ) {
              $end = DB::table('anuncio_enderecos')->where('id', '=', $existe->address_id)->update($end);
            }
          } else {
            DB::table('anuncio_enderecos')->insert($end);
          }

          if (!$log) {
            echo 'Imóvel N°: ' . $index . ' Anúncio Código: ' . $D['CodigoImovel'] . "\n";
          }
        }

        if ($type != 'data' || $newAd == true) {
          $imagesAd = '[]';
          if ($type != 'data' && $newAd != true) {
            if (!isset($imagesAds)) {
              $imagesAds = IntegrationService::getImagesForEachAd($adIds);
            }
            $id = $ads->whereStrict('codigo', $D['CodigoImovel'])->last()->id;
            $imagesAd = $imagesAds->where('anuncio_id', $id)->values();
          }
          foreach ($D->fotos as $indexImage => $f) {
            if (stristr($f['name'], 'youtube.com') == true) {
              unset($D->fotos[$indexImage]);
            } else {
              $D->fotos[$indexImage]['url'] = $f['name'];
              $D->fotos[$indexImage]['name'] = 'integration/' . md5($user_id . $id . $f['name']) . '.jpg';
            }
          }

          if ($imagesAd != '[]') {
            $validaImage = true;
            foreach ($imagesAd as $indexImage => $f) {
              if ($validaImage) {
                try {
                  if ($f->name == $D->fotos[$indexImage]['name']) {
                    IntegrationService::donwloadImagesIfNotExist(
                      $D->fotos[$indexImage]['name'],
                      $D->fotos[$indexImage]['url']
                    );
                    unset($D->fotos[$indexImage]);
                    unset($imagesAd[$indexImage]);
                  } else {
                    IntegrationService::removeOldImages($imagesAd);
                    DB::table('anuncio_images')->where('id', '>=', $f->id)->where('anuncio_id', $id)->delete();
                    $validaImage = false;
                  }
                } catch (\Exception $e) {
                  IntegrationService::removeOldImages($imagesAd);
                  DB::table('anuncio_images')->where('id', '>=', $f->id)->where('anuncio_id', $id)->delete();
                  $validaImage = false;
                }
              }
            }
          }
          $countQtdImage = 1;
          $dataImages = null;
          foreach ($D->fotos as $f) {
            if ($countQtdImage <= 20) {
              try {
                $image = $f['name'];
                $img = public_path('images/') . $image;
                file_put_contents($img, file_get_contents($f['url']));
                IntegrationService::reduceImage($image);
                $images = [
                  'anuncio_id' => $id,
                  'name' => $image,
                  'created_at' => Carbon::now()->toDateTimeString(),
                ];
                $dataImages[] = $images;
                $countQtdImage++;
              } catch (\Exception $e) {
              }
            }
          }
          if ($countQtdImage > 1) {
            DB::table('anuncio_images')->insert($dataImages);
          }
          if (!$log) {
            echo 'Imagens N°: ' . $index . ' Anúncio Código: ' . $D['CodigoImovel'] . "\n";
          }
        }
      }
    }
    IntegrationService::removeOldData($imovelData, $user_id);
    if ($type == null) {
      IntegrationService::moveHighlight($user_id);
      IntegrationService::attManualAds($user_id);
    }
    return $qtd;
  }

  public static function getPossibleCondominium($ceps)
  {
    return DB::table('imoveis')->select('id', 'cep', 'endereco')->whereIn('cep', $ceps)->get();
  }

  public static function getPossibleDistricts($cities, $districts)
  {
    return DB::table('bairros')
      ->select('id', 'slug', 'slug_cidade', 'uf_true as uf')
      ->whereIn('slug_cidade', $cities)
      ->whereIn('slug', $districts)
      ->get();
  }






  public static function getPossibleCondominiumsData($adIds)
  {
    return DB::table('condominium_data')->whereIn('ad_id', $adIds)->get();
  }






  public static function getBenefitsAmountForEachAd($adIds)
  {
    return DB::table('anuncio_beneficio')
      ->select('anuncio_id')
      ->selectRaw('COUNT(id) as amount')
      ->whereIn('anuncio_id', $adIds)
      ->groupBy('anuncio_id')
      ->get();
  }






  public static function getImagesForEachAd($adIds)
  {
    return DB::table('anuncio_images')->whereIn('anuncio_id', $adIds)->get();
  }

  public static function searchCondominium($condominiums, $cep, $street, $number)
  {
    $condominium = null;
    if (!empty($street) && !empty($number) && count($condominiums) > 0) {
      $location = $street . ', ' . $number;
      $condominium = $condominiums
        ->where('cep', $cep)
        ->where('endereco', 'like', '%' . $location . '%')
        ->first();
      $condominium = $condominiums
        ->where('cep', $cep)
        ->filter(function ($item) use ($location) {
          return false !== stripos($item->endereco, $location);
        })
        ->first();
    }
    return $condominium;
  }

  public static function removeOldData($imovelData, $user_id)
  {
    $imoveisXML = DB::table('anuncios')->where('user_id', $user_id)->where('xml', 1)->get();
    $qtdAnuncios = count($imovelData);
    foreach ($imoveisXML as $xml) {
      $delete = true;
      for ($i = 0; $i < $qtdAnuncios; $i++) {
        if ($imovelData[$i]['CodigoImovel'] == $xml->codigo) {
          $delete = false;
        }
      }
      if ($delete) {
        $images = DB::table('anuncio_images')->where('anuncio_id', $xml->id)->get();
        IntegrationService::removeOldImages($images);
        DB::table('anuncio_images')->where('anuncio_id', '=', $xml->id)->delete();
        DB::table('anuncio_beneficio')->where('anuncio_id', '=', $xml->id)->delete();
        DB::table('anuncio_enderecos')->where('anuncio_id', '=', $xml->id)->delete();
        DB::table('lista_corretores_da_construtora')->where('anuncio_id', '=', $xml->id)->delete();
        DB::table('anuncios')->where('id', '=', $xml->id)->delete();
      }
    }
    return true;
  }

  public static function attManualAds($user_id)
  {
    DB::table('anuncios')
      ->where('user_id', $user_id)
      ->where('xml', 0)
      ->update([
        'ig_highlight' => 0,
        'status' => 'desativado',
      ]);

    return true;
  }

  public static function moveHighlight($user_id)
  {
    $ads = DB::table('anuncios')->select('codigo')->where('user_id', $user_id)->where('ig_highlight', 1)->get();
    $ads = $ads->pluck('codigo')->all();
    DB::table('anuncios')
      ->where('user_id', $user_id)
      ->where('xml', 1)
      ->whereIn('codigo', $ads)
      ->update([
        'ig_highlight' => 1,
      ]);
  }

  public static function removeOldImages($images)
  {
    foreach ($images as $item) {
      if (file_exists(public_path('images/integration/' . str_replace('integration/', '', $item->name)))) {
        unlink(public_path('images/integration/' . str_replace('integration/', '', $item->name)));
      }
      if (
        file_exists(public_path('images/integration/properties/medium/' . str_replace('integration/', '', $item->name)))
      ) {
        unlink(public_path('images/integration/properties/medium/' . str_replace('integration/', '', $item->name)));
      }
      if (
        file_exists(public_path('images/integration/properties/small/' . str_replace('integration/', '', $item->name)))
      ) {
        unlink(public_path('images/integration/properties/small/' . str_replace('integration/', '', $item->name)));
      }
    }
  }

  public static function donwloadImagesIfNotExist($image, $url)
  {
    if (!file_exists(public_path('images/integration/' . str_replace('integration/', '', $image)))) {
      $img = public_path('images/') . $image;
      file_put_contents($img, file_get_contents($url));

      IntegrationService::reduceImage($image);
    } elseif (
      !file_exists(public_path('images/integration/properties/medium/' . str_replace('integration/', '', $image))) ||
      !file_exists(public_path('images/integration/properties/small/' . str_replace('integration/', '', $image)))
    ) {
      IntegrationService::reduceImage($image);
    }
  }

  public static function detectNegotiation($venda, $locacao, $temporada)
  {
    if ($venda > 0 && $locacao > 0 && $temporada > 0) {
      $negotiation = 5;
    } elseif ($venda > 0) {
      if ($locacao > 0) {
        $negotiation = 3;
      } elseif ($temporada > 0) {
        $negotiation = 6;
      } else {
        $negotiation = 1;
      }
    } elseif ($locacao > 0) {
      if ($temporada > 0) {
        $negotiation = 7;
      } else {
        $negotiation = 2;
      }
    } elseif ($temporada > 0) {
      $negotiation = 4;
    } else {
      $negotiation = 1;
    }
    return $negotiation;
  }

  public static function tipoImovel($nomeImovel, $log = null)
  {
    switch (trim(preg_replace('/(\v|\s)+/', ' ', str_replace('<![CDATA[', '', str_replace(']]>', '', $nomeImovel))))) {
      case 'Apartamento':
      case 'APARTAMENTO':
      case '/ APARTAMENTO ALTO PADRÃO':
      case 'Residential / Apartment':
      case 'APARTAMENTO DUPLEX':
      case 'Residential / Village House':
      case 'RESIDENCIAL / APTO ALUGUEL':
      case 'RESIDENCIAL / CHÁCARA EM CONDOMÍNIO':
      case 'RESIDENCIAL / APTO LANÇAMENTO':
      case 'RESIDENCIAL / CHACARA EM CONDOMINIO':
      case 'RESIDENCIAL / CASA/APARTAMENTO':
      case 'Apartamentos  > Venda  > Propriedades Individuais':
      case 'Apartamentos  > Venda  > Lançamentos':
      case 'Flat - Apart Hotel  > Venda':
      case 'RESIDENCIAL / PERMUTA':
      case 'Residential / Apartamento':
      case 'Apartamentos &gt; Venda':
      case 'Apartamentos &gt; Aluguel':
      case 'Apartamento de Condomínio':
      case 'Apartamento Apartamento de Condomínio':
      case 'Apartamento Residencial':
      case 'apartamento residencial':
      case 'Apartamento Apartamento Residencial':
      case 'Apartamento Padrão':
      case 'Apartamento Apartamento Padrão':
      case 'apartamento apartamento padrao':
      case 'RESIDENCIAL / APARTAMENTO DUPLEX':
      case 'RESIDENCIAL / DUPLEX':
      case 'PADRÃO / DUPLEX':
      case 'TEMPORADA / RESORT':
      case 'TEMPORADA / POUSADA':
      case 'RESIDENCIAL / BOX/GARAGEM':
      case 'RESIDENCIAL / LANÇAMENTO':
      case 'Imóvel Comercial Conjunto Comercial/sala':
      case 'Apartamentos > venda':
      case 'apartamento':
      case 'Comercial / Edificio Residencial':
      case 'apartamento padrao':
      case 'APARTAMENTO TRIPLEX':
      case 'Apartamento Duplex Residencial':
      case 'Apartamento COBERTURA DUPLEX':
      case 'Apartamento Frente Mar':
      case 'Apartamento duplex Padrão':
      case 'Apartamento Duplex Apartamento Duplex Residencial':
      case 'Apartamento Garden':
      case 'Apartamento Apartamento Garden':
      case 'Apartamentos prontos Padrão':
      case 'Conjunto Residencial':
      case 'Conjunto Conjunto Residencial':
      case 'Commercial / Apartment':
      case 'RESIDENCIAL / APARTAMENTO GARDEN':
      case 'RESIDENCIAL / APARTAMENTO TRIPLEX':
      case 'Apartamento Triplex Residencial':
      case 'Apartamento Triplex Apartamento Triplex Residencial':
      case 'Triplex':
      case 'RESIDENCIAL / COBERTURA TRIPLEX':
      case 'LANÇAMENTO':
      case 'Lançamento':
      case 'Apartamento RESIDENCIAL':
      case 'Apartamentos':
      case 'Apartamento DUPLEX':
      case 'Apartamento alto padrao':
      case 'APARTAMENTO Apartamento Padrão':
      case 'Apartamento com Área Privativa':
      case 'Residential/Apartment':
      case 'Apartamento GARDEM':
      case 'Apartamento ALTO PADRAO':
      case 'Apartamento Cobertura Duplex':
      case 'Apartamento Na Praia Brava':
      case 'Apartamento Na Planta':
      case 'Apartamentos > Aluguel':
      case 'Apartamento Apartamento':
      case 'Apartamento Apartamento 2 dormitórios':
      case 'Apartamento Apartamento 3 dormitórios':
      case 'Lançamento Apartamento 2 dormitórios':
      case 'Apartamentos > Venda':
      case 'Lançamento Alto Padrão':
      case 'Lançamento Apartamento Residencial':
      case 'Empreendimento':
      case '/ EMPREENDIMENTO - APARTAMENTOS':
      case 'Apartamento Tipo Casa':
      case 'Apartamento duplex':
      case 'Apartamentos > aluguel':
      case 'Conjunto Residencial Conjunto Residencial':
      case 'Apartamento Apartamento Padrao':
      case 'Residencial APARTAMENTOS':
      case 'Residencial APARTAMENTO  307':
      case 'Residencial APARTAMENTO  403':
      case 'Residencial APARTAMENTO  405':
      case 'Residencial APARTAMENTO  407':
      case 'RESIDENCIAL / QUARTO':
      case 'RESIDENCIAL / APARTAMENTOS PREMIUM':
      case 'RESIDENCIAL / APARTAMENTO MOBILIADO':
      case 'Apartamento Apartamento padrao':
      case 'Residencial Apartamento Duplex':
      case 'Residencial Apartamento':
      case 'PADRÃO / APARTAMENTO TIPO PADRÃO, TORRE':
      case 'RESIDENCIAL / APARTAMENTO TIPO ( PADRÃO, TOR':
      case 'Apartamento Alto Padrão':
      case 'Chácaras > Venda':
        return 1;
        break;
      case 'Casa':
      case 'casa':
      case 'CASA':
      case 'Casas > aluguel':
      case 'RESIDENCIAL / CASA TERCEIROS':
      case 'Residential / Home':
      case 'Residential / Casa':
      case 'Casas  > Venda  > Lançamentos':
      case 'Casas &gt; Venda':
      case 'Casas &gt; Aluguel':
      case 'Casa de Condomínio':
      case 'RESIDENCIAL / CASA TÉRREA':
      case 'MISTO / CASA TÉRREA':
      case 'Casa Padrão':
      case 'CASA Casa Padrão':
      case 'Casa Casa Padrão':
      case 'casa casa padrao':
      case 'Casa Plana':
      case '/ CASA EM CONDOMÍNIO':
      case '/ CASA ALTO PADRÃO':
      case 'Prédio Casa Padrão':
      case 'RESIDENCIAL / SALAO':
      case 'RESIDENCIAL / 0':
      case 'RESIDENCIAL / MOBILIADO':
      case 'RESIDENCIAL / UNDEFINED':
      case 'Residential / HABI':
      case 'RESIDENCIAL / APARTAMENTO ALTO PADRÃO':
      case 'Casa de rua':
      case 'RESIDENCIAL / MANSÃO':
      case 'casa padrao':
      case 'residencial':
      case 'RESIDENCIAL / NORMAL':
      case 'Casa Residencial':
      case 'PENTHOUSE':
      case 'Casa / Sobrado em Condomínio Residencial':
      case 'Casa Casa Residencial':
      case 'CASA NA PICADA CAFÉ':
      case 'Resort Residencial':
      case 'Resort Resort Residencial':
      case 'Commercial / Home':
      case 'COMERCIAL / SALA OU CONJUNTO':
      case 'COMERCIAL / APART HOTEL':
      case 'COMERCIAL Loja/Salão':
      case 'COMERCIAL / STUDIO':
      case 'Comercial/industrial conjunto comercial/sala':
      case 'COMERCIAL / PREDIO COMERCIAL':
      case 'Comercial Conjunto Comercial/Sala':
      case 'Comercial sala comercial':
      case 'COMERCIAL / CONJUNTO COMERCIAL/SALA':
      case 'Comercial/Industrial Pousada/Chalé':
      case 'RESIDENCIAL / GARDEN':
      case 'RESIDENCIAL / PENSIONATO':
      case 'RESIDENCIAL / ÁREA PRIVATIVA':
      case 'RESIDENCIAL / CASA CONDOMÍNIO FECHADO':
      case 'RESIDENCIAL / GEMINADO':
      case 'COMERCIAL / IMÓVEL COMERCIAL':
      case 'Bangalô Residencial':
      case 'barracao COMERCIAL':
      case 'Casas > venda':
      case 'Edícula':
      case 'Edícula Edícula':
      case 'RESIDENCIAL / TERRENO COM CASA ANTIGA':
      case 'RESIDENCIAL':
      case 'RESIDENCIAL / DIVERSOS':
      case '/ SOBRADO EM CONDOMÍNIO':
      case 'Casa / Sobrado Comercial COMERCIAL':
      case 'Casa / Sobrado RESIDENCIAL':
      case 'Casa / Sobrado RURAL':
      case 'RESIDENCIAL / CASA OU SOBRADO':
      case 'COMERCIAL / CASA OU SOBRADO':
      case 'TEMPORADA / CASA OU SOBRADO':
      case 'Casa Sobrado':
      case 'Casas > Aluguel':
      case 'casa Casa de Condomínio':
      case 'Casa Apartamento Padrão':
      case 'RESIDENCIAL / PAVILHÃO':
      case 'Home':
      case 'Casa duplex':
      case 'Casa Triplex':
      case 'RESIDENCIAL / CASA DUPLEX':
      case 'Bangalô Bangalô Residencial':
      case 'Casa Casa':
      case 'Casa Casa Padrao':
      case 'Residencial Mansão':
      case 'Residencial Casa':
      case 'Casa Casa padrao':
      case 'Residencial CASA':
      case 'RESIDENCIAL / CASA DE PRAIA':
      case 'Residencial':
      case 'RESIDENCIAL / RURAL':
      case 'RESIDENCIAL /':
      case 'RESIDENCIAL / CASA / MANSÃO':
      case 'PADRÃO / CASAS PRONTAS':
      case 'Casa Alto Padrão':
        return 2;
        break;
      case 'Chácara':
      case 'Rural Chácara':
      case '/ RURAIS':
      case 'Residential / Farm Ranch':
      case 'CHÁCARA':
      case 'Chacara':
      case 'CHACARA':
      case 'Chácaras &gt; Venda':
      case 'Residential / Chácara':
      case 'RESIDENCIAL / CHACARA':
      case 'Cháracara':
      case 'Chácara Rural':
      case 'Chácara Chácara Rural':
      case 'chacara RESIDENCIAL':
      case 'chacara RURAL':
      case 'chacara COMERCIAL':
      case 'COMERCIAL / CHACARA':
      case 'Rurais Chácara':
      case 'Chácara em Condomínio':
      case 'Residencial Chácara':
      case 'RURAL / CHÁCARA SÓ TERRA':
      case 'Rural Chacara':
      case 'Rural Terreno':
      case 'Rural Area Rural':
      case 'RURAL / LOTE':
      case 'Sítio / Chácara':
      case 'Lojas Comerciais > Aluguel':
        return 3;
        break;
      case 'CONDOMÍNIO FECHADO':
      case 'Casa de Condomínio':
      case 'Casa Casa de Condomínio':
      case 'Casa em Condomínio':
      case 'Casa de condomínio Padrão':
      case 'Residential / Condo':
      case 'CASA DE CONDOMÍNIO':
      case 'Casa Em Condomínio':
      case 'Casa casa de condominio':
      case 'RESIDENCIAL / CASA EM CONDOMÍNIO':
      case 'RESIDENCIAL / CASA EM CONDOMINIO':
      case 'Residential / Casa de Condomínio':
      case 'Condomínio Fechado':
      case 'RURAL / CASA EM CONDOMINIO':
      case 'Salas Comerciais > Aluguel':
      case 'Terrenos > Venda > Lançamentos':
      case 'Outros Imóveis > Aluguel':
      case 'CASA EM CONDOMINIO':
      case 'RESIDENCIAL / SOBRADO DE CONDOMÍNIO':
      case 'Sobrado em Condomínio':
      case 'Casa / Sobrado em Condomínio RESIDENCIAL':
      case 'RESIDENCIAL / CASA / SOBRADO EM CONDOMÍNIO F':
      case 'Casa em Condominio':
      case 'TEMPORADA / CASA EM CONDOMÍNIO':
      case 'Galpões > Venda':
      case 'Galpões > Aluguel':
      case 'RESIDENCIAL / CONDOMÍNIO':
      case 'Casa de condomínio Casa Padrão':
      case 'CASA EM CONDOMÍNIO':
      case 'Casa de condomínio':
      case '/ CASA EM CONDOMINIO':
      case 'Casa de Condom&#237;nio':
      case 'RESIDENCIAL / CASA DE CONDOMINÍO':
      case 'Casa  casa de condominio':
      case 'RESIDENCIAL / CASA DE PRAIA EM CONDOMÍNIO':
      case 'RESIDENCIAL / CONDOMÍNIO FECHADO':
      case 'Terrenos > Venda > Propriedades Individuais':
        return 4;
        break;
      case 'Flat':
      case 'Apartamento Flat':
      case 'RESIDENCIAL / FLAT':
      case 'Residential / flat':
      case 'Residential / Flat':
      case 'Flat/Aparthotel':
      case 'undefined RESIDENCIAL':
      case 'FLAT':
      case 'FLAT':
      case 'Flat - Apart Hotel &gt; Venda':
      case 'COMERCIAL / FLAT':
      case 'Flat Residencial':
      case 'Flat Flat Residencial':
      case 'Flat/aparthotel Suíte Hoteleira':
      case 'Flat Padrão':
      case 'Flat Flat':
      case '/ FLAT':
      case 'Flat/Aparthotel Flat':
      case 'Flat Flat Padrão':
      case 'Flat/aparthotel  flat':
      case 'Flat/aparthotel flat':
      case 'Residencial Flat':
      case 'NÃO INFORMADO / LANÇAMENTO':
      case 'NÃO INFORMADO / CASA EM CONDOMÍNIO':
      case 'RESIDENCIAL / CASA TRIPLEX':
      case 'NÃO INFORMADO / CASA TÉRREA':
      case 'PADRÃO / HOTÉIS/FLATS':
        return 5;
        break;
      case 'Área':
      case 'Área Padrão':
      case 'Lote / Terreno':
      case 'Lote Padrão':
      case 'Residential / Land Lot':
      case 'TERRENO':
      case 'Lote / Terreno Residencial':
      case 'TERRENO EM CONDOMÍNIO':
      case 'Terreno em condomínio Padrão':
      case 'Lote Terreno':
      case 'Terreno':
      case 'Lote/Terreno':
      case 'Terreno Loteamento/Condominio':
      case 'AREA':
      case 'Residential / Terreno':
      case 'Terrenos &gt; Venda':
      case 'Terrenos &gt; Aluguel':
      case 'Terreno Padrão':
      case 'Terreno Terreno Comercial':
      case 'Terreno Terreno Padrão':
      case 'Terreno Lote Alto Padrão':
      case 'TEMPORADA / CASA EM CONDOMINIO':
      case 'RESIDENCIAL / CHÁCARA SÓ TERRA':
      case 'RESIDENCIAL / LOTE':
      case '/ LOTE':
      case 'Residential / Land/Lot':
      case 'Residential / Farm/Ranch':
      case 'RESIDENCIAL / ÁREA DE TERRA':
      case 'Terrenos > venda':
      case 'ALUGUEL/VENDA / LOTE':
      case 'Lote':
      case 'RESIDENCIAL / FLAT - APART-HOTEL':
      case 'TEMPORADA / FLAT':
      case 'TEMPORADA / APTO. COBERTURA':
      case 'terreno RESIDENCIAL':
      case 'RESIDENCIAL / COBERTURAS':
      case 'area RESIDENCIAL':
      case 'Lote/Terreno Padrão':
      case 'Lote/Terreno Terreno Padrão':
      case 'Terreno / Área Terreno Padrão':
      case 'Terreno / Área Padrão':
      case 'RESIDENCIAL / AREA':
      case 'Casa Terreno Padrão':
      case 'Casa Padrao':
      case 'Terreno / Área':
      case 'Área residencial':
      case 'Terreno Terreno':
      case 'Terreno Terreno Padrao':
      case 'Residencial Terreno':
      case 'Terreno Terreno padrao':
      case 'TERRENO /':
      case 'RESIDENCIAL / LOTES / LOTEAMENTO':
      case 'PADRÃO / LOTES':
      case 'terreno':
        return 6;
        break;
      case 'Sobrado':
      case 'SOBRADO':
      case 'Residential / Sobrado':
      case 'Sobrado Residencial':
      case 'Sobrado Sobrado Residencial':
      case 'Sobrado Padrão':
      case 'Sobrado Sobrado Padrão':
      case 'sobrado RESIDENCIAL':
      case 'Sobrado Sobrado':
      case 'Residencial Sobrado':
      case 'RESIDENCIAL / CASA ASSOBRADADA':
      case 'Casa / Sobrado':
      case 'CASA SOBRADO':
      case 'CASA CASA CONDOMÍNIO':
        return 7;
        break;
      case 'Cobertura':
      case 'Cobertura Cobertura':
      case 'Apartamento Cobertura':
      case 'Cobertura duplex Padrão':
      case 'COBERTURA':
      case 'Residential / Penthouse':
      case 'Residential/Penthouse':
      case 'Residential / Cobertura':
      case 'RESIDENCIAL / COBERTURA':
      case 'Penthouse':
      case 'RESIDENCIAL / COBERTURA DUPLEX':
      case 'Cobertura Residencial':
      case 'Cobertura Cobertura Residencial':
      case 'ALUGUEL/VENDA / COBERTURA':
      case 'Coberturas':
      case 'RESIDENCIAL / APTO. COBERTURA':
      case 'COBERTURA DUPLEX':
      case 'APTO. COBERTURA':
      case 'Commercial / Land/Lot':
      case 'Cobertura duplex':
      case 'Penthouse Penthouse Residencial':
      case 'Residencial Cobertura':
      case 'RESIDENCIAL / COBERTURA SEM CONDOMÍNIO':
      case 'PADRÃO / COBERTURA':
      case 'PADRÃO / COBERTURA DUPLEX':
        return 8;
        break;
      case 'Kitnet':
      case 'KITNET':
      case 'Residential / Kitnet':
      case 'Andar Inteiro':
      case 'Apartamento APARTAMENTO PADRAO':
      case 'Apartamento Casa Padrão':
      case 'Apartamento APARTAMENTO':
      case 'Apartamento Conjunto Comercial/Sala':
      case 'Apartamento Apartamento na Planta':
      case 'Apartamento Apartamento 3 Dormitórios':
      case 'Apartamento /':
      case 'Apartamento NA PLANTA':
      case 'Residential / Studio':
      case 'Apartamento Pré-Lançamento':
      case 'Kitchenette/Conjugados':
      case 'Apartamento Kitchenette/Conjugados':
      case 'Apartamentos > Venda > Propriedades Individuais':
      case 'Apartamento Residencial Padrão':
      case 'Apartamento Studio':
      case 'Apartamento Indústria':
      case 'Apartamento / Padrao':
      case 'Apartamento Pronto para Morar':
      case 'Apartamento / Cobertura Duplex':
      case 'RESIDENCIAL / KITINETE':
      case 'Kitnet Residencial':
      case 'Kitnet Kitnet Residencial':
      case 'Kitnet/Conjugado':
      case 'Apartamento Kitchenette':
      case 'Conjugado':
      case 'Kitnet Kitnet':
      case 'RESIDENCIAL / QUITINETE':
      case 'PADRÃO / QUITINETE - HOTEL RESIDENCIAL':
      case 'Ponto comercial':
      case 'COMERCIAL / LOJA/SALAO':
      case 'COMERCIAL / CLÍNICA':
        return 9;
        break;
      case 'Consultório':
      case 'COMERCIAL / CONSULTÓRIO':
      case 'Comercial/industrial loja/salao':
      case 'Comercial Área Comercial':
      case 'Comercial / Casa Comercial':
      case 'Comercial / Predio Comercial':
      case 'Comercial Predio Inteiro':
      case 'Comercial Pousada':
      case 'Commercial / Consultorio':
      case 'Casa em Condomínio Casa em Condomínio':
      case 'Casa Térrea Casa Térrea':
      case 'Sala Comercial Sala Comercial':
        return 10;
        break;
      case 'Edifício Residencial':
      case 'Commercial / Edificio Residencial':
      case 'Comercial Indústria':
      case 'Pousada':
      case 'POUSADA':
      case 'Prédio':
      case 'PREDIO':
      case 'Prédio Padrão':
      case 'Commercial / Edifício Residencial':
      case 'COMERCIAL / POUSADA':
      case 'Prédio Inteiro':
      case 'VENDA / COBERTURA':
      case 'ALUGUEL / LOJA':
      case 'VENDA / LOJA':
      case 'ALUGUEL / COBERTURA':
      case 'RESIDENCIAL / EDÍCULA':
      case 'COMERCIAL / ÁREA COMERCIAL':
      case 'Imóvel Comercial Prédio Inteiro':
      case 'Comercial/Industrial Prédio Inteiro':
      case 'VENDA / CASA COMERCIAL':
      case 'Comercial Prédio Inteiro':
      case 'PRÉDIO':
      case 'Pousada Temporada':
      case 'Pousada Pousada Temporada':
      case 'COMERCIAL / POUSADA/HOTEL':
      case 'Prédio Residencial':
      case 'RESIDENCIAL/COMERCIAL / POUSADA':
      case 'Pousada/Hotel':
      case 'pousada':
        return 11;
        break;
      case 'Sala Comercial':
      case 'Sala Sala Comercial':
      case 'SALA COMERCIAL':
      case 'Commercial / Sala Comercial':
      case 'CONJUNTO':
      case 'SALAS COMERCIAIS PRONTOS':
      case 'Conjunto Sala Comercial':
      case 'salas comerciais prontos Padrão':
      case 'SALA':
      case 'Sala':
      case 'Salão':
      case 'Escritório':
      case 'Salas Comerciais &gt; Aluguel':
      case 'Apartamento / COBERTURA DUPLEX':
      case 'Salas Comerciais &gt; Venda':
      case 'Commercial / Office':
      case 'Comercial / sala comercial':
      case 'Conjunto Comercial/Sala':
      case 'Comercial/Industrial Conjunto Comercial/Sala':
      case 'Conjunto Comercial/sala':
      case 'Sala Comercial Conjunto Comercial/sala':
      case 'Comercial Conjunto Comercial/sala':
      case 'COMERCIAL / SALA COMERCIAL':
      case 'RESIDENCIAL / SALA COMERCIAL':
      case 'RESIDENCIAL / COMERCIAL':
      case 'Grupo de Salas Comerciais':
      case 'Andar Corporativo':
      case 'COMERCIAL / ANDAR CORRIDO':
      case 'Laje Comercial':
      case 'Laje Laje Comercial':
      case 'COMERCIAL / LAJE COMERCIAL':
      case 'Grupo de Salas Comerciais Comercial':
      case 'Andar Corporativo Prédio Comercial':
      case 'Conj.Comercial / Sala COMERCIAL':
      case 'COMERCIAL / ANDAR CORPORATIVO':
      case 'COMERCIAL / ANDAR':
      case 'Ponto Comercial Conjunto Comercial/sala':
      case 'Salas/Conjuntos':
      case 'Salão Sala Comercial':
      case 'Casa Conjunto Comercial/Sala':
      case 'Casas > Venda > Propriedades Individuais':
      case 'Sala comercial Comercial':
      case 'Salas Comerciais > Venda > Propriedades Individuais':
      case 'COMERCIAL / SALA/CONJUNTO':
      case 'INDUSTRIAL / COMERCIAL':
      case 'COMERCIAL / SALAS|CONJUNTOS':
      case 'Sala comercial':
      case 'Grupo de Salas Comerciais Padrão':
      case 'Conjunto Conjunto Comercial':
      case 'Sala Sala':
      case 'Comercial/ Industrial Conjunto Comercial/sala':
      case 'Comercial Escritório':
      case 'Comercial/Industrial Studio':
      case 'Comercial Sala':
      case 'Comercial Conjunto Comercial':
      case 'Comercial Conjunto comercial':
      case 'PADRÃO / SALAS COMERCIAIS EM OBRAS':
      case 'PADRÃO / SALAS COMERCIAIS PRONTOS':
      case 'PADRÃO / SALA COMERCIAL':
        return 12;
        break;
      case 'Fazenda/Sítio':
      case 'FAZENDA':
      case 'Sítios &gt; Venda':
      case 'Fazenda':
      case 'Rural Fazenda':
      case 'Rural Sitio':
      case 'rural comercial':
      case 'Comercial Galpao/Depesito/Barracao':
      case 'Comercial Loja/Salao':
      case 'Commercial / Fazenda':
      case 'SITIO':
      case 'Fazenda Sítio':
      case 'Rural':
      case 'RURAL CHÁCARA':
      case 'rural':
      case 'Fazendas &gt; Venda':
      case 'RURAL / HARAS':
      case 'RURAL / RANCHO':
      case 'RURAL / UNDEFINED':
      case 'RESIDENCIAL / GRANJA':
      case 'Sítio':
      case 'Rural Sítio':
      case 'Chácara Sítio':
      case 'Fazenda / Sítio Sítio':
      case 'Haras':
      case 'Rural Sítio com Benfeitorias':
      case 'Rural Haras':
      case 'Terrenos, Sítios e Fazendas':
      case 'Rurals > venda':
      case 'Fazenda Rural':
      case 'Fazenda Fazenda Rural':
      case 'Sítio Rural':
      case 'Sítio Sítio Rural':
      case 'RURAL / CHÁCARA / SÍTIO':
      case 'RURAL / SÍTIO ÁREA DE TERRAS CHÁCARA':
      case 'RESIDENCIAL / SÍTIO ÁREA DE TERRAS CHÁCARA':
      case 'RURAL / RURAL':
      case 'RURAL / SÍTIO/ÁREA DE TERRAS/CHÁCARA':
      case 'Pousada/Chalé':
      case 'Comercial Pousada/Chalé':
      case 'RURAL':
      case 'sitio RESIDENCIAL':
      case 'fazenda RESIDENCIAL':
      case 'haras COMERCIAL':
      case 'sitio RURAL':
      case 'area RURAL':
      case 'Área Rural':
      case 'terreno RURAL':
      case 'Terreno Terreno em Condomínio':
      case 'Rancho Rancho Residencial':
      case 'Commercial / Agricultural':
      case 'Residential / Agricultural':
      case 'haras RESIDENCIAL':
      case 'LAZER / RANCHO EM CONDOMINIO':
      case 'LAZER / RANCHO':
      case 'Lojas Comerciais > Venda':
      case 'RESIDENCIAL / RANCHO':
      case 'PADRÃO / RURAL':
      case 'RESIDENCIAL / CHALÉ':
      case 'Residencial Chalé':
      case 'Haras Haras Rural':
      case 'RURAL /':
      case 'Sitio':
      case 'RESIDENCIAL / HARAS':
      case 'TEMPORADA / RANCHO':
      case 'Im&#243;vel Rural':
      case 'Chácara Chácara':
        return 13;
        break;
      case 'GALPAO':
      case 'Galpão':
      case 'Galpão Industrial':
      case 'Galpão Deposito Armazem':
      case 'ARMAZEM':
      case 'Galpões &gt; Venda':
      case 'Galpões &gt; Aluguel':
      case 'Commercial / Galpão':
      case 'Galpão Terreno Industrial':
      case 'Casa de Condomínio':
      case 'Galpão/Depósito/Armazém':
      case 'Galpão/Depósito/Armazém Galpão/Depósito/Armazém':
      case 'Comercial/Industrial Galpão/Depósito/Armazém':
      case 'Comercial Galpão/Depósito/Armazém':
      case 'armazem INDUSTRIAL':
      case 'RESIDENCIAL / POUSADA':
      case 'Galpao':
      case 'Galpão/Depósito/Barracão':
      case 'Comercial Galpão/Depósito/Barracão':
      case 'Galpão Comercial':
      case 'Galpão Padrão':
      case 'Galpão Galpão Comercial':
      case 'Barracão Comercial':
      case 'Barracão Barracão':
      case 'Barracão Barracão Comercial':
      case 'Barracão / Galpão COMERCIAL':
      case 'galpao INDUSTRIAL':
      case 'Terreno Galpão/Depósito/Armazém':
      case 'INDUSTRIAL / GALPAO':
      case 'INDUSTRIAL / COMERCIAL/INDUSTRIAL':
      case 'COMERCIAL / GALPAO':
      case 'Casa Galpão/Depósito/Armazém':
      case 'COMERCIAL / GALPÃO / BARRACÃO':
      case 'COMERCIAL / PAVILHÃO':
      case 'GALPÃO / BARRACÃO':
      case 'GALPÃO/DEPÓSITO/ARMAZÉM / COMERCIAL / INDUSTRIAL':
      case 'Galpão Comercial Galpão Comercial':
      case 'Industrial Galpão':
      case 'Pavilhão':
      case 'Comercial/industrial Galpao/deposito/armazem':
      case 'Pavilhão Pavilhão':
        return 14;
        break;
      case 'COMERCIAL':
      case 'comercial comercial':
      case 'salao COMERCIAL':
      case 'Comercial':
      case 'Imóvel Comercial':
      case 'Comércio':
      case 'Commercial / Building':
      case 'Commercial / Edificio Comercial':
      case 'Commercial / Imóvel Comercial':
      case 'COMERCIAL / CASA TÉRREA':
      case 'Commercial / Casa':
      case 'Commercial / Retail':
      case 'Commercial / Industrial':
      case 'Comercial/Industrial':
      case 'SALAO':
      case 'salao INDUSTRIAL':
      case 'COMERCIAL / SALAO':
      case 'Commercial / Sobrado':
      case '/ COMERCIAL':
      case 'COMERCIAL / COMERCIAL':
      case 'COMERCIAL / DIVERSOS':
      case 'COMERCIAL / PRÉDIO COMERCIAL':
      case 'COMERCIAL / CASA COMERCIAL':
      case 'Casa Comercial':
      case 'Casa Linear':
      case 'Comercial/Industrial Casa Comercial':
      case 'Casa comercial':
      case 'Casa Casa em Condomínio':
      case 'Casa Alvenaria':
      case 'Casas > Venda':
      case 'Casa Apartamento Duplex Residencial':
      case 'Casa comercial Comercial':
      case 'Imóvel Comercial Casa Comercial':
      case 'COMERCIAL / ESPAÇO CORPORATIVO':
      case 'Commercial':
      case 'COMERCIAL / UNDEFINED':
      case 'COMERCIAL / HARAS':
      case 'Commercial / Residential Income':
      case 'Hotel':
      case 'Comercial/Industrial Hotel':
      case 'HOTEL':
      case 'INDUSTRIAL / CENTRO LOGÍSTICO':
      case 'Comercials > venda':
      case 'Comercials > aluguel':
      case 'Prédio Comercial':
      case 'Prédio Prédio Comercial':
      case 'Área Comercial':
      case 'Área Área Comercial':
      case 'Salão Comercial':
      case 'Salão Salão Comercial':
      case 'Hotel Residencial':
      case 'Hotel Hotel Residencial':
      case 'COMERCIAL / ESTRUTURA COMERCIAL':
      case 'Comercial Loja de Shopping/Centro Comercial':
      case 'comercial':
      case 'INDUSTRIAL':
      case 'Prédio comercial Padrão':
      case 'Apartamento Duplex':
      case 'Imóvel Comercial Padrão':
      case 'Imóvel Commercial':
      case 'Comercial/Industrial Indústria':
      case 'RESIDENCIAL / SALA / SALÃO COMERCIAL':
      case 'RESIDENCIAL / TERRENO CONDOMÍNIO FECHADO':
      case 'COMERCIAL / SALA / SALÃO COMERCIAL':
      case 'Comercial Casa Comercial':
      case 'Predio Comercial':
      case 'RESIDENCIAL / PRÉDIO COMERCIAL':
      case 'COMERCIAL / POSTO DE GASOLINA':
      case 'Loja Apartamento 1 Quarto':
      case 'Lojas Comerciais  > Venda':
      case 'Outros Imóveis  > Venda':
      case 'COMERCIAL / IMOVEL COMERCIAL':
      case 'Prédio comercial':
      case 'INDUSTRIAL / ÁREA INDUSTRIAL':
      case 'PRÉDIO COMERCIA':
      case 'PRÉDIO COMERCIAL':
      case 'Casa de Condomínio':
      case 'RESIDENCIAL / ÁREA INDUSTRIAL':
      case 'COMERCIAL / SALÃO COMERCIAL':
      case 'Comercial predio  comercial':
      case 'Comercial Prédio':
      case 'Comercial/Industrial Casa comercial':
      case 'Comercial Casa comercial':
      case 'COMERCIAL /':
      case 'COMERCIAL / IMÓVEIS COM RENDA':
      case 'Casa Casa Comercial':
      case 'Outros Imóveis > Venda':
        return 15;
        break;
      case 'Loja':
      case 'Commercial / Loja':
      case 'COMERCIAL / LOJA':
      case '/ LOJA':
      case 'COMERCIAL / LOJA TÉRREA':
      case 'COMERCIAL / LOJÃO':
      case 'COMERCIAL/TURISMO / LOJA TÉRREA':
      case 'Comercial / Loja':
      case 'Loja de Shopping/Centro Comercial':
      case 'Comercial/Industrial Loja de Shopping/Centro Comercial':
      case 'Loja Comercial':
      case 'Loja Comércio':
      case 'Loja Loja Comercial':
      case 'Loja / Comércio COMERCIAL':
      case 'Loja/Salão':
      case 'Comercial/Industrial Loja/Salão':
      case 'Casa Independente em condomínio':
      case 'Loja Loja/Salão':
      case 'Loja Casa Padrão':
      case 'COMERCIAL / LOJA COMERCIAL':
      case 'Loja Loja':
      case 'LOJA':
      case 'Comercial Loja/Salão':
      case 'Comercial/ Industrial Loja/Salão':
      case 'Comercial/Industrial Loja/Salao':
      case 'Comercial Loja':
      case 'PADRÃO / LOJA':
      case 'COMERCIAL/TURISMO / PRÉDIO COMERCIAL':
      case 'PADRÃO / LOJAS SHOPPING':
        return 16;
        break;
      case 'Commercial / Land Lot':
      case 'Commercial / Terreno':
      case 'COMERCIAL / LOTE':
      case 'AREA COMERCIAL':
      case 'area COMERCIAL':
      case 'terreno COMERCIAL':
      case 'Terrenos - Lotes > Venda':
      case 'terreno INDUSTRIAL':
      case 'Terreno industrial Padrão':
      case 'area INDUSTRIAL':
      case 'INDUSTRIAL / AREA':
      case 'COMERCIAL / TERRENO COMERCIAL':
      case 'Área industrial':
      case 'Área industrial Padrão':
      case 'Terreno / Loteamento/condominio':
      case 'Terreno / Terreno padrao':
      case 'ÁREA INDUSTRIAL':
      case 'INDUSTRIAL / IMÓVEL COMERCIAL':
      case 'Terreno comercial':
      case 'RESIDENCIAL / TERRENO INDUSTRIAL':
      case '/ TERRENO INDUSTRIAL':
      case '/ TERRENO COMERCIAL':
      case 'Terreno Comercial':
        return 17;
        break;
      case 'Commercial / Business':
      case 'Comercial/industrial / Galpao/deposito/armazem':
      case 'Apartamento / Loft':
      case 'Apartamento / Pronto para Morar':
      case 'Comercial/industrial / loja/salao':
      case 'COMERCIO':
      case 'Ponto Comercial':
      case 'Ponto Ponto Comercial':
      case 'Industria':
      case 'PONTO':
      case 'ponto RESIDENCIAL':
      case 'COMERCIAL / COMÉRCIO':
      case 'COMERCIAL / NEGÓCIO MONTADO':
      case 'PADRÃO / COMERCIAL/INDUSTRIAL':
      case 'SOBRADO / COMERCIAL/INDUSTRIAL':
      case 'Comercial Estabelecimento':
      case 'TERRENO LOTEAMENTO/CONDOMINIO':
      case 'Rural / Terreno':
      case 'POSTO DE GASOLINA':
      case 'MALL':
      case 'Flat/aparthotel / flat':
      case 'FRIGORÍFICO':
      case 'INDUSTRIA, SUPERMERCADOS E DER':
      case 'Apartamento / Apartamento padrao':
      case 'COMERCIAL / INDÚSTRIA':
        return 18;
        break;
      case '/ DIVERSOS':
      case 'Casa / Casa padrao':
      case 'COMERCIAL/TURISMO / SEDE CAMPESTRE':
      case 'Diversos':
      case 'Padrão':
      case '1':
      case '2':
      case 'Residential':
      case '0 / 0':
      case 'UNDEFINED / UNDEFINED':
      case 'Desconhecido':
      case 'Outros':
      case 'ILHA':
      case '/ USA INVESTIMENTS':
      case '/ 0':
      case 'Residencial Ilha Particular':
      case '10':
      case '13':
      case '9':
      case '5':
      case '11':
      case '8':
      case 'Outros Imóveis':
      case 'Outros Imoveis':
      case 'outros imóveis':
      case 'Outros Imóveis':
      case 'Andar Corporativo Apartamento Residencial':
      case 'Outros Im&#243;veis':
      case '19':
      case 'Ilha Ilha Residencial':
      case 'Bangalô':
      case 'Terreno Terreno Industrial':
      case 'RESIDENCIAL / CASA CONSTRUTORA':
      case 'CORPORATIVA / ANDAR CORPORATIVO':
        return 19;
        break;
      case 'RESIDENCIAL / LOFT':
      case 'Loft':
      case 'Apartamento Loft':
      case 'Loft Residencial':
      case 'Loft Loft Residencial':
      case 'PADRÃO / LOFT':
        return 20;
        break;
      case 'STUDIOS':
      case 'STUDIO':
      case 'RESIDENCIAL / STUDIO':
      case 'RESIDENCIAL / APARTAMENTOS':
      case 'RESIDENCIAL / CASAS':
      case 'Studio':
      case 'Kitchenette/Studio':
      case 'Kitnet Kitchenette/Studio':
      case 'Apartamento Kitchenette/Studio':
      case 'Studio Kitchenette/Studio':
      case 'RESIDENCIAL / ESTUDIO':
      case 'Studios Casa Padrão':
      case 'RESIDENCIAL / STÚDIO':
      case 'Studio Studio':
        return 21;
        break;
      case 'Casa de Vila':
      case 'Casa Casa de Vila':
      case 'Casa Casa Alto Padrão':
      case 'Village Residencial':
      case 'Village Village Residencial':
      case 'Resort Hotel':
      case 'RESIDENCIAL / VILLAGE':
      case 'RESIDENCIAL / CASA DE VILA':
      case 'MULTIUSO: TURISTICO OU RESIDEN / VISTA PARA O MAR':
      case 'MULTIUSO: TURISTICO OU RESIDEN / AREA DE PRAIA FRENTE AO MAR':
        return 22;
        break;
      case 'Loteamento/Condomínio':
      case 'Terreno Loteamento/Condomínio':
      case 'Terreno Lote padrão':
      case 'Terreno em Condomínio RESIDENCIAL':
      case 'LAZER / TERRENO CONDOMINIO DE RANCHO':
      case 'Terreno em Condomínio':
      case 'Terreno em condomínio':
      case 'Studio Kitnet/Studio':
      case 'RESIDENCIAL / TERRENO CONDOMÍNIO':
      case 'Studio Apartamento Residencial':
      case 'Studio Apartamento Padrão':
      case 'Cobertura Cobertura Duplex':
      case 'Sobrado Casa de Condomínio':
      case 'Studio Flat':
      case 'Lançamento Apartamento 3 dormitórios':
      case 'Apartamento Apartamento 2 Quartos':
      case 'Apartamento Garden Apartamento Garden':
      case 'Sobrado Sobrado em Condomínio':
      case 'Apartamento Apartamento 1 Quarto':
      case 'Sala Sala Comercial/Nova':
      case 'Casa Casas 1 Quarto':
      case 'Casa Casa 2 dormitórios':
      case 'Apartamento Triplex Apartamento de Condomínio':
      case 'Sala Conjunto Comercial/sala':
      case 'Cobert Apartamento de Condomínio':
      case 'Lançamento Apartamento Garden':
      case 'Sobrado CASA EM CONDOMÍNIO':
      case 'Terreno Residencial':
      case 'Casa Independente':
      case 'Loteamento Condomínio':
      case 'Comercial Prédio Comercial':
      case 'Sala Sobrado Residencial':
      case 'Chácara, Sítios e flats':
      case 'Residencial / Flat':
      case 'TERRENO EM CONDOMINIO':
      case 'Terreno Loteamento/condominio':
        return 23;
        break;
      case 'RESIDENCIAL / GARAGEM':
      case 'RESIDENCIAL / BANGALÔ':
      case 'RESIDENCIAL / BOX / GARAGEM':
      case 'RESIDENCIAL / VILLAGIO':
      case 'Box/Garagem':
      case 'Comercial/Industrial Box/Garagem':
      case 'COMERCIAL / SALA LIVING':
      case 'Box/Garagem Comercial':
      case 'Box/Garagem Box/Garagem Comercial':
      case 'COMERCIAL / BOX':
      case 'Vaga de garagem':
      case 'Garagem':
      case 'COMERCIAL / BOX/GARAGEM':
      case 'ESTACIONAMENTO DE VEÍCULO / GARAGEM':
      case 'Comercial Box/Garagem':
      case 'Comercial/industrial Predio inteiro':
      case 'Industrial Pedreira área de mineração':
        return 24;
        break;
      default:
        if (
          trim(preg_replace('/(\v|\s)+/', ' ', str_replace('<![CDATA[', '', str_replace(']]>', '', $nomeImovel)))) !=
            '' &&
          trim(preg_replace('/(\v|\s)+/', ' ', str_replace('<![CDATA[', '', str_replace(']]>', '', $nomeImovel)))) !=
            'undefined'
        ) {
          if (!$log) {
            dd(
              "ERRO Tipo de Imovel\n" .
                trim(
                  preg_replace('/(\v|\s)+/', ' ', str_replace('<![CDATA[', '', str_replace(']]>', '', $nomeImovel)))
                ) .
                "\n"
            );
          } else {
            fwrite(
              $log,
              "ERRO Tipo de Imovel\n" .
                trim(
                  preg_replace('/(\v|\s)+/', ' ', str_replace('<![CDATA[', '', str_replace(']]>', '', $nomeImovel)))
                ) .
                "\n"
            );
          }
          return false;
        }
        return 19;
        break;
    }
  }

  public static function verifyShowAddress($mostrarEndereco, $log)
  {
    switch (trim($mostrarEndereco)) {
      case '':
      case 'All':
      case 'ALL':
        $mostrarEndereco = 2;
        break;
      case 'Street':
        $mostrarEndereco = 1;
        break;
      case 'Neighborhood':
        $mostrarEndereco = 0;
        break;
      default:
        if (!$log) {
          dd("ERRO em Mostrar Endereço\n" . trim($mostrarEndereco) . "\n");
        } else {
          fwrite($log, "ERRO em Mostrar Endereço\n" . trim($mostrarEndereco) . "\n");
        }
        return false;
        break;
    }
    return $mostrarEndereco;
  }

  public static function fixCEP($cep)
  {
    $cep = str_replace('.', '', str_replace(' ', '', $cep));
    if (stristr($cep, '-') == false) {
      $cep = substr_replace($cep, '-', 5, 0);
    }
    return str_pad($cep, 9, '0');
  }

  public static function fixUf($uf, $log)
  {
    switch (trim($uf)) {
      case null:
      case '':
      case ' ':
      case '--':
      case 'Pernambuco - PE':
      case 'Rio Grande do Sul - RS':
      case 'NI':
      case 'Rondônia - RO':
      case 'Santa Catarina - SC':
      case 'ESPIRITO SANTO':
      case 'Paraná':
      case 'Rio de Janeiro':
      case 'Minas Gerais - MG':
      case 'Rio de Janeiro - RJ':
      case 'São Pauio':
      case 'Niassa':
      case 'Andalucía':
      case 'Paraná - PR':
      case '<![CDATA[]]>':
      case '<![CDATA[FL]]>':
      case '<![CDATA[SA]]>':
      case '<![CDATA[NN]]>':
      case '37':
      case 'UY':
      case 'AR-B':
      case 'AZ':
      case 'A':
      case 'AR-P':
      case 'Florença':
      case 'Lucca':
      case 'Siena':
      case 'Lisboa':
      case 'Caué':
      case 'IL':
      case 'CUN':
      case 'Ri':
      case 'BC':
      case 'BV':
      case 'NM':
      case 'FL':
      case 'NOVO':
      case '00':
      case '97':
        $uf = '';
        break;
      case 'AC':
      case 'AL':
      case 'AM':
      case 'AP':
      case 'BA':
      case 'CE':
      case 'DF':
      case 'ES':
      case 'GO':
      case 'MA':
      case 'MG':
      case 'MS':
      case 'MT':
      case 'BR':
      case 'PA':
      case 'PB':
      case 'PE':
      case 'PI':
      case 'PR':
      case 'RJ':
      case 'RN':
      case 'RO':
      case 'RR':
      case 'RS':
      case 'SC':
      case 'SE':
      case 'SP':
      case 'TO':
      case 'ac':
      case 'al':
      case 'am':
      case 'ap':
      case 'ba':
      case 'ce':
      case 'df':
      case 'es':
      case 'go':
      case 'ma':
      case 'mg':
      case 'ms':
      case 'mt':
      case 'pa':
      case 'pb':
      case 'Sã':
      case 'Sa':
      case 'pe':
      case 'pi':
      case 'pr':
      case 'rj':
      case 'rn':
      case 'ro':
      case 'rr':
      case 'rs':
      case 'sc':
      case 'se':
      case 'sp':
      case 'to':
      case 'é':
      case 'G':
      case '<![CDATA[AC]]>':
      case '<![CDATA[AL]]>':
      case '<![CDATA[AM]]>':
      case '<![CDATA[AP]]>':
      case '<![CDATA[BA]]>':
      case '<![CDATA[CE]]>':
      case '<![CDATA[DF]]>':
      case '<![CDATA[ES]]>':
      case '<![CDATA[GO]]>':
      case '<![CDATA[MA]]>':
      case '<![CDATA[MG]]>':
      case '<![CDATA[MS]]>':
      case '<![CDATA[MT]]>':
      case '<![CDATA[PA]]>':
      case '<![CDATA[PB]]>':
      case '<![CDATA[PE]]>':
      case '<![CDATA[PI]]>':
      case '<![CDATA[PR]]>':
      case '<![CDATA[RJ]]>':
      case '<![CDATA[RN]]>':
      case '<![CDATA[RO]]>':
      case '<![CDATA[RR]]>':
      case '<![CDATA[RS]]>':
      case '<![CDATA[SC]]>':
      case '<![CDATA[SE]]>':
      case '<![CDATA[SP]]>':
      case '<![CDATA[TO]]>':
      case '<![CDATA[Ac]]>':
      case '<![CDATA[Al]]>':
      case '<![CDATA[Am]]>':
      case '<![CDATA[Ap]]>':
      case '<![CDATA[Ba]]>':
      case '<![CDATA[Ce]]>':
      case '<![CDATA[Df]]>':
      case '<![CDATA[Es]]>':
      case '<![CDATA[Go]]>':
      case '<![CDATA[Ma]]>':
      case '<![CDATA[Mg]]>':
      case '<![CDATA[Ms]]>':
      case '<![CDATA[Mt]]>':
      case '<![CDATA[Pa]]>':
      case '<![CDATA[Pb]]>':
      case '<![CDATA[Pe]]>':
      case '<![CDATA[Pi]]>':
      case '<![CDATA[Pr]]>':
      case '<![CDATA[Rj]]>':
      case '<![CDATA[Rn]]>':
      case '<![CDATA[Ro]]>':
      case '<![CDATA[Rr]]>':
      case '<![CDATA[Rs]]>':
      case '<![CDATA[Sc]]>':
      case '<![CDATA[Se]]>':
      case '<![CDATA[Sp]]>':
      case '<![CDATA[To]]>':
      case '<![CDATA[ac]]>':
      case '<![CDATA[al]]>':
      case '<![CDATA[am]]>':
      case '<![CDATA[ap]]>':
      case '<![CDATA[ba]]>':
      case '<![CDATA[ce]]>':
      case '<![CDATA[df]]>':
      case '<![CDATA[es]]>':
      case '<![CDATA[go]]>':
      case '<![CDATA[ma]]>':
      case '<![CDATA[mg]]>':
      case '<![CDATA[ms]]>':
      case '<![CDATA[mt]]>':
      case '<![CDATA[pa]]>':
      case '<![CDATA[pb]]>':
      case '<![CDATA[pe]]>':
      case '<![CDATA[pi]]>':
      case '<![CDATA[pr]]>':
      case '<![CDATA[rj]]>':
      case '<![CDATA[Rj]]>':
      case '<![CDATA[rn]]>':
      case '<![CDATA[ro]]>':
      case '<![CDATA[rr]]>':
      case '<![CDATA[rs]]>':
      case '<![CDATA[sc]]>':
      case '<![CDATA[se]]>':
      case '<![CDATA[sp]]>':
      case '<![CDATA[Bahia]]>':
      case '<![CDATA[Rio Grande do Sul]]>':
      case '<![CDATA[Rio de Janeiro]]>':
      case '<![CDATA[Santa Catarina]]>':
      case '<![CDATA[Minas Gerais]]>':
      case '<![CDATA[to]]>':
        $uf = $uf;
        break;
      case 'BA  - Distrito':
      case 'Bahia':
        $uf = 'BA';
        break;
      case 'Ma':
        $uf = 'MA';
        break;
      case 'Pa':
        $uf = 'PA';
        break;
      case 'PE  - Distrito':
      case 'Bahia - BA':
      case 'Pernambuco':
      case '<![CDATA[Pernambuco]]>':
        $uf = 'PE';
        break;
      case 'Rio De Janeiro':
      case 'RJ  - Distrito':
        $uf = 'RJ';
        break;
      case 'Santa Catarina':
      case 'Sc':
        $uf = 'SC';
        break;
      case 'São Paulo':
      case 'SÃO PAULO':
      case 'São paulo':
      case 'São Paulo - SP':
      case '<![CDATA[São Paulo]]>':
      case '<![CDATA[SAO PAULO]]>':
      case '<![CDATA[SP ]]>':
      case 'Sp':
        $uf = 'SP';
        break;
      case '<![CDATA[Rio Grande do Norte]]>':
        $uf = 'RN';
        break;
      case 'Rs':
        $uf = 'RS';
        break;
      default:
        if (!$log) {
          dd("ERRO Tipo de UF\n" . trim($uf) . "\n");
        } else {
          fwrite($log, "ERRO Tipo de UF\n" . trim($uf) . "\n");
        }
        return 'SKIP';
        break;
    }
    return $uf;
  }

  public static function remove_emoji($string)
  {

    $regex_alphanumeric = '/[\x{1F100}-\x{1F1FF}]/u';
    $clear_string = preg_replace($regex_alphanumeric, '', $string);

    $regex_symbols = '/[\x{1F300}-\x{1F5FF}]/u';
    $clear_string = preg_replace($regex_symbols, '', $clear_string);

    $regex_emoticons = '/[\x{1F600}-\x{1F64F}]/u';
    $clear_string = preg_replace($regex_emoticons, '', $clear_string);

    $regex_transport = '/[\x{1F680}-\x{1F6FF}]/u';
    $clear_string = preg_replace($regex_transport, '', $clear_string);

    $regex_supplemental = '/[\x{1F900}-\x{1F9FF}]/u';
    $clear_string = preg_replace($regex_supplemental, '', $clear_string);

    $regex_misc = '/[\x{2600}-\x{26FF}]/u';
    $clear_string = preg_replace($regex_misc, '', $clear_string);

    $regex_dingbats = '/[\x{2700}-\x{27BF}]/u';
    $clear_string = preg_replace($regex_dingbats, '', $clear_string);

    return $clear_string;
  }

  public static function get_string_between($string, $start, $end)
  {
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) {
      return '';
    }
    $ini += strlen($start);

    $last = strpos($string, $end, $ini);
    if ($last == 0) {
      return '';
    }
    $len = $last - $ini;
    return self::remove_emoji(substr($string, $ini, $len));
  }

  public static function replace_between($str, $needle_start, $needle_end, $replacement)
  {
    $pos = strpos($str, $needle_start);
    $start = $pos === false ? 0 : $pos + strlen($needle_start);

    $pos = strpos($str, $needle_end, $start);
    $end = $pos === false ? strlen($str) : $pos;

    return substr_replace($str, $replacement, $start, $end - $start);
  }

  public static function replaceAsc($string)
  {
    $string = str_replace('&#243;', 'ó', $string);
    $string = str_replace('&lt;br&gt;', ' ', $string);
    $string = str_replace('&lt;b&gt;', '', $string);
    $string = str_replace('&lt;/b&gt;', '', $string);
    $string = str_replace('<p>', '', $string);
    $string = str_replace('</p>', '', $string);
    $string = str_replace('<strong>', '', $string);
    $string = str_replace('</strong>', '', $string);
    $string = str_replace('</span>', '', $string);
    $string = str_replace('<em>', '', $string);
    $string = str_replace('</em>', '', $string);
    $string = str_replace('<span style="font-size:16px">', '', $string);
    $string = str_replace('<span style="font-size:14px">', '', $string);
    $string = str_replace('<span style="font-size:12px">', '', $string);
    $string = str_replace('<span style="font-size:11px">', '', $string);
    return $string;
  }

  public static function reduceImage($nome_img)
  {
    $nome_img = str_replace('integration/', '', $nome_img);
    $thumbnailpath = public_path('images/integration/' . $nome_img);
    if (file_exists($thumbnailpath)) {
      copy($thumbnailpath, public_path('images/integration/properties/small/' . $nome_img));
      copy($thumbnailpath, public_path('images/integration/properties/medium/' . $nome_img));

      $img = Image::make(public_path('images/integration/properties/medium/' . $nome_img))
        ->orientate()
        ->resize(360, 280, function ($constraint) {
          $constraint->aspectRatio();
        });
      $img->save(public_path('images/integration/properties/medium/' . $nome_img));

      $img = Image::make(public_path('images/integration/properties/small/' . $nome_img))
        ->orientate()
        ->resize(280, 250, function ($constraint) {
          $constraint->aspectRatio();
        });
      $img->save(public_path('images/integration/properties/small/' . $nome_img));

      $img = Image::make($thumbnailpath)
        ->orientate()
        ->resize(768, 432, function ($constraint) {
          $constraint->aspectRatio();
        });
      $img->save($thumbnailpath);
    }
  }
}