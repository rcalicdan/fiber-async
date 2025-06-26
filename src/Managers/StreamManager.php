<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\Stream\StreamWatcherHandler;
use Rcalicdan\FiberAsync\Handlers\Stream\StreamSelectHandler;

class StreamManager
{
    /** @var \Rcalicdan\FiberAsync\ValueObjects\StreamWatcher[] */
    private array $watchers = [];

    private StreamWatcherHandler $watcherHandler;
    private StreamSelectHandler $selectHandler;

    public function __construct()
    {
        $this->watcherHandler = new StreamWatcherHandler();
        $this->selectHandler = new StreamSelectHandler();
    }

    public function addStreamWatcher($stream, callable $callback): void
    {
        $watcher = $this->watcherHandler->createWatcher($stream, $callback);
        $this->watchers[] = $watcher;
    }

    public function processStreams(): void
    {
        $readyStreams = $this->selectHandler->selectStreams($this->watchers);
        
        if (!empty($readyStreams)) {
            $this->selectHandler->processReadyStreams($readyStreams, $this->watchers);
        }
    }

    public function hasWatchers(): bool
    {
        return !empty($this->watchers);
    }
}