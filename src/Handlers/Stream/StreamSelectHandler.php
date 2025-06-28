<?php

namespace Rcalicdan\FiberAsync\Handlers\Stream;

use Rcalicdan\FiberAsync\ValueObjects\StreamWatcher;

/**
 * Handles stream selection and readiness detection.
 * 
 * This class uses PHP's stream_select() function to monitor multiple
 * streams for readiness and process those that are ready for I/O.
 * 
 * @package Rcalicdan\FiberAsync\Handlers\Stream
 * @author  Rcalicdan
 */
final readonly class StreamSelectHandler
{
    /**
     * Select streams that are ready for reading.
     * 
     * Uses stream_select() to check which streams in the watcher array
     * are ready for reading without blocking.
     * 
     * @param StreamWatcher[] $watchers Array of stream watchers to check
     * @return resource[] Array of streams that are ready for reading
     */
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

    /**
     * Process streams that are ready for I/O.
     * 
     * Finds the watchers corresponding to ready streams, executes their
     * callbacks, and removes them from the watchers array.
     * 
     * @param resource[]        $readyStreams Array of ready streams
     * @param StreamWatcher[]  &$watchers     Reference to watchers array
     * @return void
     */
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