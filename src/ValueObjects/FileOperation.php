<?php
// src/ValueObjects/FileOperation.php

namespace Rcalicdan\FiberAsync\ValueObjects;

class FileOperation
{
    private string $id;
    private string $type;
    private string $path;
    private mixed $data;
    /** @var callable */
    private $callback;
    private array $options;
    private float $createdAt;

    public function __construct(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ) {
        $this->id = uniqid('file_', true);
        $this->type = $type;
        $this->path = $path;
        $this->data = $data;
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

    public function getPath(): string
    {
        return $this->path;
    }

    public function getData(): mixed
    {
        return $this->data;
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
            error_log('File operation callback error: ' . $e->getMessage());
        }
    }
}