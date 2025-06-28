<?php

namespace Rcalicdan\FiberAsync\Handlers\Stream;

use Rcalicdan\FiberAsync\ValueObjects\StreamWatcher;

final readonly class StreamSelectHandler
{
    public function selectStreams(array $watchers): array
    {
        if (empty($watchers)) {
            return [];
        }

        $read = $write = $except = [];

        foreach ($watchers as $watcher) {
            $read[] = $watcher->getStream();
        }

        $result = stream_select($read, $write, $except, 0);
        
        return $result > 0 ? $read : [];
    }

    public function processReadyStreams(array $readyStreams, array &$watchers): void
    {
        foreach ($readyStreams as $stream) {
            foreach ($watchers as $key => $watcher) {
                if ($watcher->getStream() === $stream) {
                    $watcher->execute();
                    unset($watchers[$key]);
                    break;
                }
            }
        }
    }
}