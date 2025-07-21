<?php

namespace Rcalicdan\FiberAsync\EventLoop\ValueObjects;

class PDOOperation
{
    private string $id;
    private string $type;
    private array $payload;
    /** @var callable */
    private $callback;
    private array $options;
    private float $createdAt;

    public function __construct(
        string $type,
        array $payload,
        callable $callback,
        array $options = []
    ) {
        $this->id = uniqid('pdo_', true);
        $this->type = $type;
        $this->payload = $payload;
        $this->callback = $callback;
        $this->options = $options;
        $this->createdAt = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    public function executeCallback(?string $error, mixed $result = null): void
    {
        try {
            ($this->callback)($error, $result);
        } catch (\Throwable $e) {
            error_log('PDO operation callback error: '.$e->getMessage());
        }
    }
}
