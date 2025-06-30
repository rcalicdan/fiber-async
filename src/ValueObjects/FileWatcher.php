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
    private float $lastChecked;

    public function __construct(string $path, callable $callback, array $options = [])
    {
        $this->id = uniqid('watcher_', true);
        $this->path = $path;
        $this->callback = $callback;
        $this->options = array_merge([
            'polling_interval' => 1.0, // Default 1 second
            'watch_size' => true,       // Watch file size changes
            'watch_content' => false,   // Watch content hash (expensive)
        ], $options);
        $this->lastModified = file_exists($path) ? filemtime($path) : 0;
        $this->lastChecked = microtime(true);
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

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getPollingInterval(): float
    {
        return $this->options['polling_interval'];
    }

    public function getLastModified(): float
    {
        return $this->lastModified;
    }

    public function updateLastModified(float $time): void
    {
        $this->lastModified = $time;
    }

    public function shouldCheck(): bool
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastChecked;

        if ($elapsed >= $this->getPollingInterval()) {
            $this->lastChecked = $now;
            return true;
        }

        return false;
    }

    public function checkForChanges(): bool
    {
        if (!file_exists($this->path)) {
            return false;
        }

        $currentModified = filemtime($this->path);
        $hasChanged = $currentModified !== $this->lastModified; 

        if (!$hasChanged && $this->options['watch_size']) {
            // Could add size-based change detection here
        }

        if (!$hasChanged && $this->options['watch_content']) {
            // Could add content hash-based detection here
        }

        return $hasChanged;
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
