<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Stream;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\StreamWatcher;

final readonly class StreamSelectHandler
{
    /**
     * Polls an array of stream watchers and returns the streams that are ready.
     *
     * @param array<int, StreamWatcher> $watchers An array of StreamWatcher objects to monitor.
     * @return array<resource> An array of stream resources that are ready for I/O.
     */
    public function selectStreams(array $watchers): array
    {
        if (count($watchers) === 0) {
            return [];
        }

        $read = $write = $except = [];
        foreach ($watchers as $watcher) {
            $stream = $watcher->getStream();
            if (is_resource($stream)) {
                if ($watcher->getType() === StreamWatcher::TYPE_READ) {
                    $read[] = $stream;
                } elseif ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                    $write[] = $stream;
                }
            }
        }

        if (count($read) === 0 && count($write) === 0) {
            return [];
        }

        @stream_select($read, $write, $except, 0);

        return array_merge($read, $write);
    }
    
    /**
     * Processes streams that are ready for I/O operations.
     *
     * @param array<resource> $readyStreams An array of stream resources that are ready.
     * @param array<int, StreamWatcher> &$watchers The master array of active watchers.
     *                                             This array is modified by removing completed
     *                                             one-shot (write) watchers.
     * @return void
     */
    public function processReadyStreams(array $readyStreams, array &$watchers): void
    {
        foreach ($readyStreams as $stream) {
            foreach ($watchers as $key => $watcher) {
                if (is_resource($watcher->getStream()) && $watcher->getStream() === $stream) {
                    $watcher->execute();
                    if ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                        unset($watchers[$key]);
                    }

                    break;
                }
            }
        }
    }
}