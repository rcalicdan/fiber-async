<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\ValueObjects\StreamWatcher;

class StreamManager
{
    /** @var StreamWatcher[] */
    private array $watchers = [];

    public function addStreamWatcher($stream, callable $callback): void
    {
        $this->watchers[] = new StreamWatcher($stream, $callback);
    }

    public function getReadStreams(): array
    {
        $streams = [];
        foreach ($this->watchers as $watcher) {
            $streams[] = $watcher->getStream();
        }
        return $streams;
    }

    public function processReadyStreams(array $readyStreams): void
    {
        if (empty($readyStreams)) {
            return;
        }
        
        foreach ($readyStreams as $stream) {
            foreach ($this->watchers as $key => $watcher) {
                if ($watcher->getStream() === $stream) {
                    $watcher->execute();
                    unset($this->watchers[$key]);
                    break;
                }
            }
        }
    }

    public function hasWatchers(): bool
    {
        return !empty($this->watchers);
    }
}