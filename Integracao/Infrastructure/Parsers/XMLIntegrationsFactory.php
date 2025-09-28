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
          $xmlFirstElement->removeAttributeNS($xmlFirstElement->getAttribute('xmlns'), '');
          $xmlFirstElement->removeAttributeNS($xmlFirstElement->getAttribute('xsi'), '');
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
    $this->logXmlStructure();

    $rootElement = $this->xml->getDocument()->documentElement;
    $normalize = function(string $name): string {
      $parts = explode(':', $name);
      return strtolower(end($parts));
    };
    $rootName = $rootElement ? $normalize($rootElement->nodeName) : '';
    $childNames = [];
    if ($rootElement) {
      foreach ($rootElement->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
          $childNames[] = $normalize($child->nodeName);
        }
      }
    }
    $allNames = $this->collectTagNames($rootElement);

    $hasListingDataFeed = $rootName === 'listingdatafeed' || in_array('listingdatafeed', $allNames, true);
    $hasListings = in_array('listings', $childNames, true) || in_array('listings', $allNames, true);
    $hasProperties = in_array('properties', $childNames, true) || in_array('properties', $allNames, true);

    if ($hasListingDataFeed && $hasListings) {
      $this->provider = new EnglishGlobalModel($this->xml, $this->integration);
    } elseif ($hasListingDataFeed && $hasProperties) {
      $this->provider = new IGModel($this->xml, $this->integration);
    } elseif (in_array('union', $allNames, true)) {
      $this->provider = new UnionModel($this->xml, $this->integration);
    } elseif (in_array('publish', $allNames, true) && $hasProperties) {
      $this->provider = new CreciModel($this->xml, $this->integration);
    } elseif (in_array('carga', $allNames, true)) {
      $this->provider = new TecImobModel($this->xml, $this->integration);
    } elseif (in_array('anuncios', $allNames, true)) {
      $this->provider = new VistaModel($this->xml, $this->integration);
    } elseif (in_array('imobibrasil', $allNames, true) || $this->isImobiBrasilFormat()) {
      $this->provider = new ImobiBrasilModel($this->xml, $this->integration);
    } elseif (in_array('opennavent', $allNames, true)) {
      $this->provider = new OpenNaventModel($this->xml, $this->integration);
    } elseif (in_array('ad', $allNames, true)) {
      $this->provider = new MigMidiaModel($this->xml, $this->integration);
    } else {
      $integrationId = $this->integration ? $this->integration->id : 'N/A';
      $integrationLink = $this->integration ? $this->integration->link : 'N/A';

      $xmlStructure = $this->getXmlStructureInfo();

      \Illuminate\Support\Facades\Log::channel('integration')->error("XML Provider Not Found", [
        'integration_id' => $integrationId,
        'integration_link' => $integrationLink,
        'xml_structure' => $xmlStructure,
        'xml_size' => strlen($this->xml->xml()),
        'root_element' => $this->xml->getDocument()->documentElement ? $this->xml->getDocument()->documentElement->nodeName : 'unknown',
        'available_providers' => [
          'ListingDataFeed + Listings' => $hasListingDataFeed && $hasListings,
          'ListingDataFeed + Properties' => $hasListingDataFeed && $hasProperties,
          'Union' => in_array('union', $allNames, true),
          'publish + properties' => in_array('publish', $allNames, true) && $hasProperties,
          'Carga' => in_array('carga', $allNames, true),
          'Anuncios' => in_array('anuncios', $allNames, true),
          'imobibrasil' => in_array('imobibrasil', $allNames, true),
          'OpenNavent' => in_array('opennavent', $allNames, true),
          'ad' => in_array('ad', $allNames, true)
        ],
        'element_checks' => [
          'has_listingdatafeed' => $hasListingDataFeed,
          'has_listings' => $hasListings,
          'has_properties' => $hasProperties,
          'has_carga' => in_array('carga', $allNames, true),
          'has_anuncios' => in_array('anuncios', $allNames, true),
          'has_imobibrasil' => in_array('imobibrasil', $allNames, true)
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


    } catch (\Exception $e) {
      \Illuminate\Support\Facades\Log::channel('integration')->warning("Failed to analyze XML structure", [
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

  private function collectTagNames(?\DOMNode $node, int $limit = 500): array
  {
    if (!$node) {
      return [];
    }

    $names = [];
    $stack = [$node];
    $processed = 0;

    while ($stack && $processed < $limit) {
      $current = array_pop($stack);
      if ($current->nodeType === XML_ELEMENT_NODE) {
        $name = strtolower($current->localName ?? $current->nodeName);
        $names[$name] = true;
        for ($i = $current->childNodes->length - 1; $i >= 0; $i--) {
          $stack[] = $current->childNodes->item($i);
        }
        $processed++;
      }
    }

    return array_keys($names);
  }

  public function setResponse($response): void
  {
    $this->response = $response;
  }
}