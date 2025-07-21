<?php

namespace Rcalicdan\FiberAsync\EventLoop\Managers;

class SocketManager
{
    private array $readWatchers = [];
    private array $writeWatchers = [];

    public function addReadWatcher($socket, callable $callback): void
    {
        $this->readWatchers[(int) $socket][] = [$socket, $callback];
    }

    public function addWriteWatcher($socket, callable $callback): void
    {
        $this->writeWatchers[(int) $socket][] = [$socket, $callback];
    }

    public function removeReadWatcher($socket): void
    {
        unset($this->readWatchers[(int) $socket]);
    }

    public function removeWriteWatcher($socket): void
    {
        unset($this->writeWatchers[(int) $socket]);
    }

    public function processSockets(): bool
    {
        if (empty($this->readWatchers) && empty($this->writeWatchers)) {
            return false;
        }

        $readableStreams = [];
        $writableStreams = [];
        $exceptionStreams = null;

        foreach ($this->readWatchers as $socketId => $watcherGroup) {
            $readableStreams[] = $watcherGroup[0][0];
        }

        foreach ($this->writeWatchers as $socketId => $watcherGroup) {
            $writableStreams[] = $watcherGroup[0][0];
        }

        $numChanged = @stream_select($readableStreams, $writableStreams, $exceptionStreams, 0, 1000);

        if ($numChanged <= 0) {
            return false;
        }

        $this->processReadySockets($writableStreams, $this->writeWatchers);

        $this->processReadySockets($readableStreams, $this->readWatchers);

        return true;
    }

    private function processReadySockets(array $readySockets, array &$watchers): void
    {
        foreach ($readySockets as $socket) {
            $socketId = (int) $socket;

            if (isset($watchers[$socketId])) {
                foreach ($watchers[$socketId] as [$_, $callback]) {
                    try {
                        $callback();
                    } catch (\Throwable $e) {
                        error_log("Error in socket callback for ID {$socketId}: ".$e->getMessage());
                    }
                }
                unset($watchers[$socketId]);
            }
        }
    }

    public function hasWatchers(): bool
    {
        return (bool) ($this->readWatchers || $this->writeWatchers);
    }

    public function clearAllWatchersForSocket($socket): void
    {
        $socketId = (int) $socket;
        unset($this->readWatchers[$socketId], $this->writeWatchers[$socketId]);
    }
}
