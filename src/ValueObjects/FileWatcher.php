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
    private int $lastSize;
    private float $lastChecked;

    public function __construct(string $path, callable $callback, array $options = [])
    {
        $this->id = uniqid('watcher_', true);
        $this->path = $path;
        $this->callback = $callback;
        $this->options = array_merge([
            'polling_interval' => 0.1, // Default 100ms for faster detection
            'watch_size' => true,       // Watch file size changes
            'watch_content' => false,   // Watch content hash (expensive)
        ], $options);

        // Initialize with current file state
        if (file_exists($path)) {
            $this->lastModified = filemtime($path);
            $this->lastSize = filesize($path);
        } else {
            $this->lastModified = 0;
            $this->lastSize = 0;
        }

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
        if (! file_exists($this->path)) {
            // File was deleted
            if ($this->lastModified > 0 || $this->lastSize > 0) {
                $this->lastModified = 0;
                $this->lastSize = 0;

                return true;
            }

            return false;
        }

        clearstatcache(true, $this->path);

        $currentModified = filemtime($this->path);
        $currentSize = filesize($this->path);

        $hasChanged = false;

        // Check modification time (allow for filesystem timestamp precision)
        if (abs($currentModified - $this->lastModified) > 0.001) {
            $hasChanged = true;
        }

        // Check file size if enabled
        if (! $hasChanged && $this->options['watch_size'] && $currentSize !== $this->lastSize) {
            $hasChanged = true;
        }

        if ($hasChanged) {
            $this->lastModified = $currentModified;
            $this->lastSize = $currentSize;

            return true;
        }

        return false;
    }

    public function executeCallback(string $event, string $path): void
    {
        try {
            ($this->callback)($event, $path);
        } catch (\Throwable $e) {
            error_log('File watcher callback error: '.$e->getMessage());
        }
    }
}
