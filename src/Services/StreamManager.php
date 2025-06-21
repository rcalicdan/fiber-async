<?php

namespace Rcalicdan\FiberAsync\Services;

use Rcalicdan\FiberAsync\ValueObjects\StreamWatcher;

class StreamManager
{
    /** @var StreamWatcher[] */
    private array $watchers = [];

    public function addStreamWatcher($stream, callable $callback): void
    {
        $this->watchers[] = new StreamWatcher($stream, $callback);
    }

    public function collectStreams(array &$read, array &$write, array &$except): void
    {
        foreach ($this->watchers as $watcher) {
            $read[] = $watcher->getStream();
        }
    }

    public function handleReadyStreams(array $read, array $write): bool
    {
        if (empty($read) && empty($write)) {
            return false;
        }

        $processed = false;

        // Handle ready read streams
        foreach ($read as $stream) {
            foreach ($this->watchers as $key => $watcher) {
                if ($watcher->getStream() === $stream) {
                    try {
                        $watcher->execute();
                        unset($this->watchers[$key]);
                        $processed = true;
                    } catch (\Throwable $e) {
                        error_log('Stream watcher error: ' . $e->getMessage());
                        unset($this->watchers[$key]);
                    }
                    break;
                }
            }
        }

        return $processed;
    }

    public function processStreams(): bool
    {
        // This method is now mainly for compatibility
        // Real processing happens in handleReadyStreams
        return false;
    }

    public function hasWatchers(): bool
    {
        return !empty($this->watchers);
    }
}
