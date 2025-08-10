<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Stream;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\StreamWatcher;

final readonly class StreamSelectHandler
{
    /**
     * Polls an array of stream watchers and returns the streams that are ready.
     *
     * @param  array<string, StreamWatcher>  $watchers  An associative array of StreamWatcher objects.
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
     * Processes streams that are ready for I/O operations using an efficient lookup.
     *
     * @param  array<resource>  $readyStreams  An array of stream resources that are ready.
     * @param  array<string, StreamWatcher>  &$watchers  The master map of active watchers, keyed by string ID.
     *                                                   This array is modified by reference.
     */
    public function processReadyStreams(array $readyStreams, array &$watchers): void
    {
        $lookupMap = [];
        foreach ($watchers as $watcherId => $watcher) {
            $stream = $watcher->getStream();
            if (is_resource($stream)) {
                $lookupMap[(int) $stream] = $watcherId;
            }
        }

        foreach ($readyStreams as $stream) {
            $socketId = (int) $stream;

            if (isset($lookupMap[$socketId])) {
                $watcherId = $lookupMap[$socketId];
                // Ensure the watcher still exists in the master list before processing.
                if (isset($watchers[$watcherId])) {
                    $watcher = $watchers[$watcherId];
                    $watcher->execute();
                    // If the watcher is a one-shot (like a WRITE), remove it.
                    if ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                        unset($watchers[$watcherId]);
                    }
                }
            }
        }
    }
}
