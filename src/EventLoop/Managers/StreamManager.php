<?php

namespace Rcalicdan\FiberAsync\EventLoop\Managers;

use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Stream\StreamSelectHandler;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Stream\StreamWatcherHandler;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\StreamWatcher;

class StreamManager
{
    private array $watchers = [];
    private StreamWatcherHandler $watcherHandler;
    private StreamSelectHandler $selectHandler;

    public function __construct()
    {
        $this->watcherHandler = new StreamWatcherHandler;
        $this->selectHandler = new StreamSelectHandler;
    }

    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        $watcher = $this->watcherHandler->createWatcher($stream, $callback, $type);
        $this->watchers[$watcher->getId()] = $watcher;

        return $watcher->getId();
    }

    public function removeStreamWatcher(string $watcherId): bool
    {
        if (isset($this->watchers[$watcherId])) {
            unset($this->watchers[$watcherId]);

            return true;
        }

        return false;
    }

    public function processStreams(): void
    {
        // Pass array_values because stream_select needs a numerically indexed array
        $readyStreams = $this->selectHandler->selectStreams(array_values($this->watchers));
        if (! empty($readyStreams)) {
            $this->selectHandler->processReadyStreams($readyStreams, $this->watchers);
        }
    }

    public function hasWatchers(): bool
    {
        return ! empty($this->watchers);
    }
}
