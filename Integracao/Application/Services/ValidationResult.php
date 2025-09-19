<?php

namespace App\Integracao\Application\Services;

class ValidationResult
{
    private bool $isValid;
    private array $errors;
    private array $data;

    private function __construct(bool $isValid, array $errors = [], array $data = [])
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->data = $data;
    }

    public static function valid(array $data = []): self
    {
        return new self(true, [], $data);
    }

    public static function invalid(array $errors = []): self
    {
        return new self(false, $errors);
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function getXmlContent(): ?string
    {
        return $this->data['xml_content'] ?? null;
    }

    public function getProvider(): ?string
    {
        return $this->data['provider'] ?? null;
    }

    public function getEstimatedSize(): ?int
    {
        return $this->data['estimated_size'] ?? null;
    }

    public function getContentType(): ?string
    {
        return $this->data['content_type'] ?? null;
    }
}
