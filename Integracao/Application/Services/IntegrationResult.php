<?php

namespace App\Integracao\Application\Services;

use App\Integracao\Domain\Entities\Integracao;

class IntegrationResult
{
    private bool $success;
    private string $message;
    private ?Integracao $integration;
    private array $data;

    private function __construct(bool $success, string $message, ?Integracao $integration = null, array $data = [])
    {
        $this->success = $success;
        $this->message = $message;
        $this->integration = $integration;
        $this->data = $data;
    }

    public static function success(Integracao $integration, string $message, array $data = []): self
    {
        return new self(true, $message, $integration, $data);
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getIntegration(): ?Integracao
    {
        return $this->integration;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
