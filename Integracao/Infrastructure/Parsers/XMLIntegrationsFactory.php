<?php

namespace App\Integracao\Infrastructure\Parsers;

use DiDom\Document;
use App\Integracao\Domain\Entities\Integracao;
use App\Integracao\Infrastructure\Parsers\Models\IGModel;
use App\Integracao\Infrastructure\Parsers\Models\VistaModel;
use App\Integracao\Infrastructure\Parsers\Models\UnionModel;
use App\Integracao\Infrastructure\Parsers\Models\CreciModel;
use App\Integracao\Infrastructure\Parsers\Models\TecImobModel;
use App\Integracao\Infrastructure\Parsers\Models\MigMidiaModel;
use App\Integracao\Infrastructure\Parsers\Models\OpenNaventModel;
use App\Integracao\Infrastructure\Parsers\Models\ImobiBrasilModel;
use App\Integracao\Infrastructure\Parsers\Models\EnglishGlobalModel;
use App\Integracao\Infrastructure\Helpers\IntegrationHelper;
use Exception;

class XMLIntegrationsFactory
{
  private $xml = null;
  private $response = null;
  private $integration = null;
  private $provider = null;

  public function __construct()
  {
    // Document será criado quando necessário para evitar problemas de serialização
  }

  public function setIntegrationAndLoadXml(Integracao $integration, $document = null)
  {
    $this->integration = $integration;

    if ($document) {
      $this->xml = $document;
      $this->findProvider();
    } else {
      $this->xml = new Document();

      try {
        if (!$this->response || !$this->response->body()) {
          throw new \Exception('Response body is null or empty');
        }
        $xmlContent = str_replace('CDDATA', 'CDATA', $this->response->body());
        $this->xml->load(
          $xmlContent,
          false,
          Document::TYPE_XML,
          LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET
        );
        $xmlFirstElement = $this->xml->getDocument()->firstChild;

        if ($xmlFirstElement && ($xmlFirstElement->getAttribute('xmlns') || $xmlFirstElement->getAttribute('xsi'))) {
          $xmlFirstElement->removeAttributeNS($xmlFirstElement->getAttribute('xmlns'), ''); // Remove xmlns.
          $xmlFirstElement->removeAttributeNS($xmlFirstElement->getAttribute('xsi'), ''); // Remove xmlns.
          $this->xml->load(
            $this->xml->xml(),
            false,
            Document::TYPE_XML,
            LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET
          );
        }

        $this->findProvider();
      } catch (Exception $e) {
        throw $e;
      }
    }
  }

  public function hasProvider(): bool
  {
    return $this->provider != null;
  }

  public function getProvider(): Mixed
  {
    return $this->provider;
  }

  public function findProvider(): void
  {
    // Log XML structure for debugging
    $this->logXmlStructure();

    if ($this->xml->has('ListingDataFeed') && ($this->xml->has('Listings') || $this->xml->has('ListingDataFeed Listings') || $this->xml->find('ListingDataFeed Listings')->count() > 0)) {
      // EnglishGlobal Model - Otimizado!
      $this->provider = new EnglishGlobalModel($this->xml, $this->integration);
    } elseif ($this->xml->has('ListingDataFeed') && $this->xml->has('Properties')) {
      // Imóvel Guide Model - Otimizado!
      $this->provider = new IGModel($this->xml, $this->integration);
    } elseif ($this->xml->has('Union')) {
      // Union Model - Otimizado!
      $this->provider = new UnionModel($this->xml, $this->integration);
    } elseif ($this->xml->has('publish') && $this->xml->has('properties')) {
      // Creci Model - Otimizado!
      $this->provider = new CreciModel($this->xml, $this->integration);
    } elseif ($this->xml->has('Carga')) {
      // TecImob Model - Otimizado!
      $this->provider = new TecImobModel($this->xml, $this->integration);
    } elseif ($this->xml->has('Anuncios')) {
      // Vista Model - Otimizado!
      $this->provider = new VistaModel($this->xml, $this->integration);
    } elseif ($this->xml->has('imobibrasil')) {
      // ImobiBrasil Model - Otimizado!
      $this->provider = new ImobiBrasilModel($this->xml, $this->integration);
    } elseif ($this->xml->has('OpenNavent')) {
      // OpenNavent Model - Otimizado!
      $this->provider = new OpenNaventModel($this->xml, $this->integration);
    } elseif ($this->xml->has('ad')) {
      // MigMidia Model - Otimizado!
      $this->provider = new MigMidiaModel($this->xml, $this->integration);
    } else {
      $integrationId = $this->integration ? $this->integration->id : 'N/A';
      $integrationLink = $this->integration ? $this->integration->link : 'N/A';

      // Enhanced error message with XML structure details
      $xmlStructure = $this->getXmlStructureInfo();

      // Log detailed XML structure for debugging
      \Illuminate\Support\Facades\Log::error("XML Provider Not Found", [
        'integration_id' => $integrationId,
        'integration_link' => $integrationLink,
        'xml_structure' => $xmlStructure,
        'xml_size' => strlen($this->xml->xml()),
        'root_element' => $this->xml->getDocument()->documentElement ? $this->xml->getDocument()->documentElement->nodeName : 'unknown',
        'available_providers' => [
          'ListingDataFeed + Listings' => $this->xml->has('ListingDataFeed') && $this->xml->has('Listings'),
          'ListingDataFeed + Listings (nested)' => $this->xml->has('ListingDataFeed') && $this->xml->has('ListingDataFeed Listings'),
          'ListingDataFeed + Properties' => $this->xml->has('ListingDataFeed') && $this->xml->has('Properties'),
          'Union' => $this->xml->has('Union'),
          'publish + properties' => $this->xml->has('publish') && $this->xml->has('properties'),
          'Carga' => $this->xml->has('Carga'),
          'Anuncios' => $this->xml->has('Anuncios'),
          'imobibrasil' => $this->xml->has('imobibrasil'),
          'OpenNavent' => $this->xml->has('OpenNavent'),
          'ad' => $this->xml->has('ad')
        ],
        'element_checks' => [
          'has_ListingDataFeed' => $this->xml->has('ListingDataFeed'),
          'has_Listings' => $this->xml->has('Listings'),
          'has_ListingDataFeed_Listings' => $this->xml->has('ListingDataFeed Listings'),
          'has_ListingDataFeed_Listings_count' => $this->xml->find('ListingDataFeed Listings')->count(),
          'has_Properties' => $this->xml->has('Properties'),
          'has_Carga' => $this->xml->has('Carga'),
          'has_Anuncios' => $this->xml->has('Anuncios'),
          'has_imobibrasil' => $this->xml->has('imobibrasil')
        ]
      ]);

      throw new \Exception("Nenhum provedor encontrado para o XML da integração ID: {$integrationId}. Estrutura XML: {$xmlStructure}");
    }
  }

  private function logXmlStructure(): void
  {
    if (!$this->integration) {
        return;
    }

    try {
      $rootElement = $this->xml->getDocument()->documentElement;
      $rootName = $rootElement ? $rootElement->nodeName : 'unknown';

      $childElements = [];
      if ($rootElement) {
        foreach ($rootElement->childNodes as $child) {
          if ($child->nodeType === XML_ELEMENT_NODE) {
            $childElements[] = $child->nodeName;
          }
        }
      }

      \Illuminate\Support\Facades\Log::info("XML Structure Analysis", [
        'integration_id' => $this->integration->id,
        'root_element' => $rootName,
        'child_elements' => array_slice($childElements, 0, 10), // First 10 children
        'total_children' => count($childElements),
        'xml_size' => strlen($this->xml->xml())
      ]);
    } catch (\Exception $e) {
      \Illuminate\Support\Facades\Log::warning("Failed to analyze XML structure", [
        'integration_id' => $this->integration->id,
        'error' => $e->getMessage()
      ]);
    }
  }

  private function getXmlStructureInfo(): string
  {
    try {
      $rootElement = $this->xml->getDocument()->documentElement;
      if (!$rootElement) {
        return 'Root element not found';
      }

      $rootName = $rootElement->nodeName;
      $childElements = [];

      foreach ($rootElement->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
          $childElements[] = $child->nodeName;
        }
      }

      $childInfo = empty($childElements) ? 'no children' : implode(', ', array_slice($childElements, 0, 5));
      if (count($childElements) > 5) {
        $childInfo .= '... (+' . (count($childElements) - 5) . ' more)';
      }

      return "Root: {$rootName}, Children: {$childInfo}";
    } catch (\Exception $e) {
      return 'Structure analysis failed: ' . $e->getMessage();
    }
  }

  public function setResponse($response): void
  {
    $this->response = $response;
  }
}