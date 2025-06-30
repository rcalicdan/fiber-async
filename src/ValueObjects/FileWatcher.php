<?php
// src/ValueObjects/FileWatcher.php

namespace Rcalicdan\FiberAsync\ValueObjects;

class FileWatcher
{
    private string $id;
    private string $path;
    /** @var callable */
    private $callback;
    private array $options;
    private float $lastModified;

    public function __construct(string $path, callable $callback, array $options = [])
    {
        $this->id = uniqid('watcher_', true);
        $this->path = $path;
        $this->callback = $callback;
        $this->options = $options;
        $this->lastModified = file_exists($path) ? filemtime($path) : 0;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getLastModified(): float
    {
        return $this->lastModified;
    }

    public function updateLastModified(float $time): void
    {
        $this->lastModified = $time;
    }

    public function checkForChanges(): bool
    {
        if (!file_exists($this->path)) {
            return false;
        }

        $currentModified = filemtime($this->path);
        return $currentModified > $this->lastModified;
    }

    public function executeCallback(string $event, string $path): void
    {
        try {
            ($this->callback)($event, $path);
        } catch (\Throwable $e) {
            error_log('File watcher callback error: ' . $e->getMessage());
        }
    }
}
