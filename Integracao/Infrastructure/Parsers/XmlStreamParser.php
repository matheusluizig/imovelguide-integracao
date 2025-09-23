<?php

namespace App\Integracao\Infrastructure\Parsers;

use XMLReader;

class XmlStreamParser
{
    private XMLReader $reader;

    public function __construct()
    {
        $this->reader = new XMLReader();
    }

    public function open(string $uri): void
    {
        if (!$this->reader->open($uri, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('Falha ao abrir XML para leitura: ' . $uri);
        }
    }

    public function iterateItems(string $itemNodeName): \Generator
    {
        while ($this->reader->read()) {
            if ($this->reader->nodeType === XMLReader::ELEMENT && $this->reader->name === $itemNodeName) {
                $node = $this->reader->readOuterXML();
                yield $node;
            }
        }
    }

    public function close(): void
    {
        $this->reader->close();
    }
}


