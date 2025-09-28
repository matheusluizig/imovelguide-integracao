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
use Exception;
use RuntimeException;

class XMLIntegrationsFactory
{
    private ?Document $xml = null;
    private $response = null;
    private ?Integracao $integration = null;
    private $provider = null;

    public function setIntegrationAndLoadXml(Integracao $integration, $document = null): void
    {
        $this->integration = $integration;

        if ($document !== null) {
            $this->xml = $document instanceof Document ? $document : new Document($document);
            $this->findProvider();
            return;
        }

        $this->xml = new Document();
        $this->loadXmlFromResponse();
        $this->findProvider();
    }

    public function hasProvider(): bool
    {
        return $this->provider !== null;
    }

    public function getProvider(): mixed
    {
        return $this->provider;
    }

    public function setResponse($response): void
    {
        $this->response = $response;
    }

    private function loadXmlFromResponse(): void
    {
        if (!$this->response || !method_exists($this->response, 'body')) {
            throw new RuntimeException('Response body is null or empty');
        }

        $body = $this->response->body();
        if ($body === null || $body === '') {
            throw new RuntimeException('Response body is null or empty');
        }

        $xmlContent = str_replace('CDDATA', 'CDATA', $body);
        $previousSetting = libxml_use_internal_errors(true);

        try {
            $this->xml->load(
                $xmlContent,
                false,
                Document::TYPE_XML,
                LIBXML_PARSEHUGE | LIBXML_NSCLEAN | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS | LIBXML_NONET
            );
        } catch (Exception $exception) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
            throw $exception;
        }

        $errors = libxml_get_errors();
        if (!empty($errors)) {
            $first = reset($errors);
            $message = $first ? trim($first->message) : 'Erro desconhecido ao carregar XML';
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
            throw new RuntimeException($message);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);
    }

    private function findProvider(): void
    {
        if (!$this->xml instanceof Document) {
            throw new RuntimeException('XML document not loaded');
        }

        $structure = $this->describeStructure();
        $tags = $structure['tags'];
        $children = $structure['children'];

        $hasListingDataFeed = $structure['root'] === 'listingdatafeed' || isset($tags['listingdatafeed']);
        $hasListings = isset($tags['listings']) || in_array('listings', $children, true);
        $hasProperties = isset($tags['properties']) || in_array('properties', $children, true);

        if ($hasListingDataFeed && $hasListings) {
            $this->provider = new EnglishGlobalModel($this->xml, $this->integration);
            return;
        }

        if ($hasListingDataFeed && $hasProperties) {
            $this->provider = new IGModel($this->xml, $this->integration);
            return;
        }

        if (isset($tags['union'])) {
            $this->provider = new UnionModel($this->xml, $this->integration);
            return;
        }

        if ((isset($tags['publish']) || in_array('publish', $children, true)) && $hasProperties) {
            $this->provider = new CreciModel($this->xml, $this->integration);
            return;
        }

        if (isset($tags['carga'])) {
            $this->provider = new TecImobModel($this->xml, $this->integration);
            return;
        }

        if (isset($tags['anuncios'])) {
            $this->provider = new VistaModel($this->xml, $this->integration);
            return;
        }

        if (isset($tags['imobibrasil']) || $this->isImobiBrasilFormat($structure)) {
            $this->provider = new ImobiBrasilModel($this->xml, $this->integration);
            return;
        }

        if (isset($tags['opennavent'])) {
            $this->provider = new OpenNaventModel($this->xml, $this->integration);
            return;
        }

        if (isset($tags['ad'])) {
            $this->provider = new MigMidiaModel($this->xml, $this->integration);
            return;
        }

        $integrationId = $this->integration ? $this->integration->id : 'N/A';
        $integrationLink = $this->integration ? $this->integration->link : 'N/A';
        $xmlStructure = $this->getXmlStructureInfo();

        \Illuminate\Support\Facades\Log::channel('integration')->error('XML Provider Not Found', [
            'integration_id' => $integrationId,
            'integration_link' => $integrationLink,
            'xml_structure' => $xmlStructure,
            'xml_size' => strlen($this->xml->xml()),
            'root_element' => $structure['root'] ?: 'unknown',
            'available_providers' => [
                'ListingDataFeed + Listings' => $hasListingDataFeed && $hasListings,
                'ListingDataFeed + Properties' => $hasListingDataFeed && $hasProperties,
                'Union' => isset($tags['union']),
                'publish + properties' => (isset($tags['publish']) || in_array('publish', $children, true)) && $hasProperties,
                'Carga' => isset($tags['carga']),
                'Anuncios' => isset($tags['anuncios']),
                'imobibrasil' => isset($tags['imobibrasil']),
                'OpenNavent' => isset($tags['opennavent']),
                'ad' => isset($tags['ad']),
            ],
            'element_checks' => [
                'has_listingdatafeed' => $hasListingDataFeed,
                'has_listings' => $hasListings,
                'has_properties' => $hasProperties,
                'has_carga' => isset($tags['carga']),
                'has_anuncios' => isset($tags['anuncios']),
                'has_imobibrasil' => isset($tags['imobibrasil']),
            ],
        ]);

        throw new RuntimeException("Nenhum provedor encontrado para o XML da integração ID: {$integrationId}. Estrutura XML: {$xmlStructure}");
    }

    /**
     * @return array{root: string, children: array<int, string>, tags: array<string, bool>}
     */
    private function describeStructure(): array
    {
        $document = $this->xml->getDocument();
        $rootElement = $document->documentElement;

        if (!$rootElement) {
            throw new RuntimeException('Root element not found');
        }

        $rootName = $this->normalizeTagName($rootElement->nodeName);
        $childNames = [];

        foreach ($rootElement->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childNames[] = $this->normalizeTagName($child->nodeName);
            }
        }

        return [
            'root' => $rootName,
            'children' => $childNames,
            'tags' => $this->collectTagNames($rootElement),
        ];
    }

    private function normalizeTagName(string $name): string
    {
        $local = strtolower($name);
        if (strpos($local, ':') !== false) {
            $segments = explode(':', $local);
            return end($segments) ?: $local;
        }

        return $local;
    }

    private function isImobiBrasilFormat(array $structure): bool
    {
        if ($structure['root'] === 'imobibrasil') {
            return true;
        }

        if ($structure['root'] === 'imoveis' && isset($structure['tags']['imovel'])) {
            return true;
        }

        $required = ['imovel', 'transacao', 'valor'];
        foreach ($required as $tag) {
            if (!isset($structure['tags'][$tag])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \DOMNode|null $node
     * @param int $limit
     * @return array<string, bool>
     */
    private function collectTagNames(?\DOMNode $node, int $limit = 500): array
    {
        if (!$node) {
            return [];
        }

        $names = [];
        $stack = [$node];
        $processed = 0;

        while (!empty($stack) && $processed < $limit) {
            $current = array_pop($stack);
            if ($current->nodeType === XML_ELEMENT_NODE) {
                $names[$this->normalizeTagName($current->nodeName)] = true;
                for ($index = $current->childNodes->length - 1; $index >= 0; $index--) {
                    $stack[] = $current->childNodes->item($index);
                }
                $processed++;
            }
        }

        return $names;
    }

    private function getXmlStructureInfo(): string
    {
        try {
            $rootElement = $this->xml->getDocument()->documentElement;
            if (!$rootElement) {
                return 'Root element not found';
            }

            $rootName = $this->normalizeTagName($rootElement->nodeName);
            $childElements = [];

            foreach ($rootElement->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $childElements[] = $this->normalizeTagName($child->nodeName);
                }
            }

            $childInfo = empty($childElements) ? 'no children' : implode(', ', array_slice($childElements, 0, 5));
            if (count($childElements) > 5) {
                $childInfo .= '... (+' . (count($childElements) - 5) . ' more)';
            }

            return "Root: {$rootName}, Children: {$childInfo}";
        } catch (Exception $e) {
            return 'Structure analysis failed: ' . $e->getMessage();
        }
    }
}
