<?php

namespace App\Integracao\Application\Services;

use App\Integracao\Infrastructure\Helpers\IntegrationHelper;
use App\Integracao\Infrastructure\Parsers\XMLIntegrationsFactory;
use App\Integracao\Domain\Entities\Integracao;
use Illuminate\Support\Facades\Http;
use DiDom\Document;

class IntegrationValidationService
{
    private XMLIntegrationsFactory $factory;
    public function __construct(XMLIntegrationsFactory $factory)
    {
        $this->factory = $factory;
    }

    public function validateIntegration(string $url): ValidationResult
    {
        try {
            $cleanUrl = IntegrationHelper::loadSafeLink($url);
            if (!filter_var($cleanUrl, FILTER_VALIDATE_URL)) {
                return ValidationResult::invalid(['URL inválida ou malformada']);
            }

            if ($this->urlAlreadyExists($cleanUrl)) {
                return ValidationResult::invalid(['Este XML já foi cadastrado em outra conta']);
            }

            $xmlValidation = $this->validateXmlContent($cleanUrl);
            if (!$xmlValidation->isValid()) {
                return ValidationResult::invalid($xmlValidation->getErrors());
            }

            $providerValidation = $this->identifyProvider($xmlValidation->getXmlContent());
            if (!$providerValidation->isValid()) {
                return ValidationResult::invalid($providerValidation->getErrors());
            }

            return ValidationResult::valid([
                'url' => $cleanUrl,
                'provider' => $providerValidation->getProvider(),
                'estimated_size' => $xmlValidation->getEstimatedSize(),
                'content_type' => $xmlValidation->getContentType()
            ]);

        } catch (\Exception $e) {
            return ValidationResult::invalid(['Erro interno do servidor']);
        }
    }

    private function validateXmlContent(string $url): ValidationResult
    {
        $maxRetries = 3;
        $retryDelay = 10;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withUserAgent('ImovelGuide/1.0 (XML Integration Service; +https://imovelguide.com.br)')
                    ->timeout(120)
                    ->get($url);

                if ($response && $response->getStatusCode() === 200) {
                    $contentType = $response->getHeader('Content-Type');
                    if (!$this->isValidXmlContentType($contentType)) {
                        return ValidationResult::invalid(['Content-Type inválido. Esperado: application/xml ou text/xml']);
                    }

                    $xmlContent = $response->body();
                    if (!$this->isValidXml($xmlContent)) {
                        return ValidationResult::invalid(['Conteúdo XML inválido ou corrompido']);
                    }

                    return ValidationResult::valid([
                        'xml_content' => $xmlContent,
                        'estimated_size' => $response->getHeader('Content-Length')[0] ?? 0,
                        'content_type' => $contentType[0] ?? 'unknown'
                    ]);
                }
            } catch (\Exception $e) {
                if ($attempt < $maxRetries) {
                    sleep($retryDelay);
                    $retryDelay *= 2;
                    continue;
                }

                return ValidationResult::invalid(['Não foi possível acessar o XML. Verifique se a URL está correta e acessível']);
            }
        }

        return ValidationResult::invalid(['Falha ao acessar XML após múltiplas tentativas']);
    }

    private function identifyProvider(string $xmlContent): ValidationResult
    {
        try {
            $document = new Document();
            $document->load($xmlContent, false, Document::TYPE_XML);

            $mockResponse = new class ($xmlContent) {
                private $content;
                public function __construct($content) {
                    $this->content = $content;
                }
                public function body() {
                    return $this->content;
                }
            };

            $this->factory->setResponse($mockResponse);

            $integration = new \App\Integracao\Domain\Entities\Integracao();
            $integration->id = 999;
            $integration->link = 'test';

            $this->factory->setIntegrationAndLoadXml($integration);

            if (!$this->factory->hasProvider()) {
                return ValidationResult::invalid(['Provedor não suportado. XML não corresponde a nenhum formato conhecido']);
            }

            return ValidationResult::valid([
                'provider' => get_class($this->factory->getProvider())
            ]);

        } catch (\Exception $e) {
            return ValidationResult::invalid(['Erro ao identificar provedor do XML: ' . $e->getMessage()]);
        }
    }

    private function urlAlreadyExists(string $url): bool
    {
        return Integracao::select('id')
            ->where('link', $url)
            ->where('status', '!=', Integracao::XML_STATUS_IGNORED)
            ->exists();
    }

    private function isValidXmlContentType(array $contentType): bool
    {
        if (empty($contentType)) {
            return false;
        }

        return stripos($contentType[0], 'application/xml') !== false ||
               stripos($contentType[0], 'text/xml') !== false;
    }

    private function isValidXml(string $content): bool
    {
        try {
            $document = new Document();
            $document->load($content, false, Document::TYPE_XML);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

}
