<?php

namespace Rcalicdan\FiberAsync\Services;

class StreamMultiplexer
{
    private array $readStreams = [];
    private array $writeStreams = [];
    private array $readCallbacks = [];
    private array $writeCallbacks = [];

    public function addReadStream($stream, callable $callback): void
    {
        $id = (int) $stream;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    public function addWriteStream($stream, callable $callback): void
    {
        $id = (int) $stream;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    public function removeReadStream($stream): void
    {
        $id = (int) $stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    public function removeWriteStream($stream): void
    {
        $id = (int) $stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    public function collectStreams(array &$read, array &$write, array &$except): void
    {
        foreach ($this->readStreams as $stream) {
            $read[] = $stream;
        }
        
        foreach ($this->writeStreams as $stream) {
            $write[] = $stream;
        }
    }

    public function handleReadyStreams(array $read, array $write): bool
    {
        $processed = false;

        foreach ($read as $stream) {
            $id = (int) $stream;
            if (isset($this->readCallbacks[$id])) {
                try {
                    $this->readCallbacks[$id]($stream);
                    $processed = true;
                } catch (\Throwable $e) {
                    error_log('Stream read callback error: ' . $e->getMessage());
                }
            }
        }

        foreach ($write as $stream) {
            $id = (int) $stream;
            if (isset($this->writeCallbacks[$id])) {
                try {
                    $this->writeCallbacks[$id]($stream);
                    $processed = true;
                } catch (\Throwable $e) {
                    error_log('Stream write callback error: ' . $e->getMessage());
                }
            }
        }

        return $processed;
    }

    public function hasStreams(): bool
    {
        return !empty($this->readStreams) || !empty($this->writeStreams);
    }
}