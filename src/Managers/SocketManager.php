<?php

namespace Rcalicdan\FiberAsync\Managers;

class SocketManager
{
    private array $readWatchers = [];
    private array $writeWatchers = [];
    public function addReadWatcher($socket, callable $callback): void
    {
        $socketId = (int) $socket;
        $this->readWatchers[$socketId] = ['socket' => $socket, 'callback' => $callback];
    }
    public function addWriteWatcher($socket, callable $callback): void
    {
        $socketId = (int) $socket;
        $this->writeWatchers[$socketId] = ['socket' => $socket, 'callback' => $callback];
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
        $readSockets = array_column($this->readWatchers, 'socket');
        $writeSockets = array_column($this->writeWatchers, 'socket');
        $exceptSockets = null;
        // The select call will return immediately if there's no activity
        $numChanged = @stream_select($readSockets, $writeSockets, $exceptSockets, 0);
        if ($numChanged === 0) {
            return false;
        }
        if (! empty($writeSockets)) {
            foreach ($writeSockets as $socket) {
                $socketId = (int) $socket;
                if (isset($this->writeWatchers[$socketId])) {
                    $watcher = $this->writeWatchers[$socketId];
                    unset($this->writeWatchers[$socketId]);
                    ($watcher['callback'])();
                }
            }
        }
        if (! empty($readSockets)) {
            foreach ($readSockets as $socket) {
                $socketId = (int) $socket;
                if (isset($this->readWatchers[$socketId])) {
                    $watcher = $this->readWatchers[$socketId];
                    unset($this->readWatchers[$socketId]);
                    ($watcher['callback'])();
                }
            }
        }
        return true;
    }
    public function hasWatchers(): bool
    {
        return ! empty($this->readWatchers) || ! empty($this->writeWatchers);
    }
    public function clearAllWatchersForSocket($socket): void
    {
        $this->removeReadWatcher($socket);
        $this->removeWriteWatcher($socket);
    }
}
