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
            // Ensure we only add valid resources to the select arrays
            if (is_resource($watcher->getStream())) {
                if ($watcher->getType() === StreamWatcher::TYPE_READ) {
                    $read[] = $watcher->getStream();
                } elseif ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                    $write[] = $watcher->getStream();
                }
            }
        }

        if (empty($read) && empty($write) && empty($except)) {
            return [];
        }

        @stream_select($read, $write, $except, 0);

        return array_merge($read, $write);
    }

    public function processReadyStreams(array $readyStreams, array &$watchers): void
    {
        foreach ($readyStreams as $stream) {
            foreach ($watchers as $key => $watcher) {
                if (is_resource($watcher->getStream()) && $watcher->getStream() === $stream) {
                    $watcher->execute();

                    // --- THE FIX IS HERE ---
                    // ONLY remove one-shot (WRITE) watchers automatically.
                    // READ watchers must be persistent for a connection and
                    // must be cleaned up manually (e.g., in Connection::close()).
                    if ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                        unset($watchers[$key]);
                    }

                    // We found the watcher for this stream, so we can stop searching.
                    break;
                }
            }
        }
    }
}
