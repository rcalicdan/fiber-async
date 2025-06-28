<?php

namespace Rcalicdan\FiberAsync\Handlers\Stream;

use Rcalicdan\FiberAsync\ValueObjects\StreamWatcher;

final readonly class StreamWatcherHandler
{
    public function createWatcher($stream, callable $callback): StreamWatcher
    {
        return new StreamWatcher($stream, $callback);
    }

    public function executeWatcher(StreamWatcher $watcher): void
    {
        $watcher->execute();
    }

    public function findWatcherByStream(array $watchers, $stream): ?StreamWatcher
    {
        foreach ($watchers as $watcher) {
            if ($watcher->getStream() === $stream) {
                return $watcher;
            }
        }
        return null;
    }
}