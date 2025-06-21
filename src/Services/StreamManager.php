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

    public function processStreams(): void
    {
        if (empty($this->watchers)) {
            return;
        }

        $read = $write = $except = [];

        foreach ($this->watchers as $watcher) {
            $read[] = $watcher->getStream();
        }

        if (stream_select($read, $write, $except, 0) > 0) {
            foreach ($read as $stream) {
                foreach ($this->watchers as $key => $watcher) {
                    if ($watcher->getStream() === $stream) {
                        $watcher->execute();
                        unset($this->watchers[$key]);

                        break;
                    }
                }
            }
        }
    }

    public function hasWatchers(): bool
    {
        return ! empty($this->watchers);
    }
}
