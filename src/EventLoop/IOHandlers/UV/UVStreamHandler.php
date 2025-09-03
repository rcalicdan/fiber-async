<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Uv;

use Rcalicdan\FiberAsync\EventLoop\Interfaces\StreamHandlerInterface;
use Rcalicdan\FiberAsync\EventLoop\Managers\UVManager;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\StreamWatcher;
use UVPoll;

/**
 * UV-based stream handler for efficient I/O monitoring.
 */
final class UVStreamHandler implements StreamHandlerInterface
{
    private UVManager $uvManager;
    private array $watchers = [];

    public function __construct()
    {
        $this->uvManager = UVManager::getInstance();
    }

    public function addStreamWatcher($stream, callable $callback, string $type): string
    {
        if (!$this->uvManager->isAvailable()) {
            throw new \RuntimeException('UV extension is not available');
        }

        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a valid resource');
        }

        $watcherId = uniqid('uv_stream_', true);
        
        $events = $this->getUVEvents($type);
        $loop = $this->uvManager->getLoop();
        
        try {
            $poll = uv_poll_init_socket($loop, $stream);
            
            uv_poll_start($poll, $events, function($poll, $status, $events) use ($callback, $watcherId, $type) {
                if ($status === 0) {
                    try {
                        $callback();
                    } catch (\Throwable $e) {
                        error_log("UV Stream watcher error: " . $e->getMessage());
                    }
                } else {
                    error_log("UV Poll error for watcher {$watcherId}: status {$status}");
                    // Could optionally remove the watcher on persistent errors
                }
            });

            $this->watchers[$watcherId] = [
                'poll' => $poll,
                'stream' => $stream,
                'type' => $type,
                'created_at' => microtime(true),
                'callback' => $callback
            ];

            $this->uvManager->incrementStreamCount();
            
            return $watcherId;
            
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to create UV poll watcher: " . $e->getMessage());
        }
    }

    public function removeStreamWatcher(string $watcherId): bool
    {
        if (!isset($this->watchers[$watcherId])) {
            return false;
        }

        $this->cleanupWatcher($watcherId);
        return true;
    }

    public function processStreams(): bool
    {
        // UV handles stream processing automatically through the event loop
        // This method exists for interface compatibility
        return !empty($this->watchers);
    }

    public function hasWatchers(): bool
    {
        return !empty($this->watchers);
    }

    public function clearAllWatchers(): void
    {
        foreach (array_keys($this->watchers) as $watcherId) {
            $this->cleanupWatcher($watcherId);
        }
    }

    /**
     * Get stream watcher statistics
     */
    public function getStreamStats(): array
    {
        $typeCount = [];
        foreach ($this->watchers as $watcher) {
            $type = $watcher['type'];
            $typeCount[$type] = ($typeCount[$type] ?? 0) + 1;
        }

        return [
            'active_watchers' => count($this->watchers),
            'watchers_by_type' => $typeCount,
        ];
    }

    private function getUVEvents(string $type): int
    {
        return match($type) {
            StreamWatcher::TYPE_READ => \UV::READABLE,
            StreamWatcher::TYPE_WRITE => \UV::WRITABLE,
            'readwrite' => \UV::READABLE | \UV::WRITABLE,
            default => \UV::READABLE
        };
    }

    private function cleanupWatcher(string $watcherId): void
    {
        if (!isset($this->watchers[$watcherId])) {
            return;
        }

        $watcher = $this->watchers[$watcherId];
        
        try {
            uv_poll_stop($watcher['poll']);
            uv_close($watcher['poll']);
        } catch (\Throwable $e) {
            error_log("Error cleaning up UV stream watcher: " . $e->getMessage());
        }
        
        unset($this->watchers[$watcherId]);
    }
}