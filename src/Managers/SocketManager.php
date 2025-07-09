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

        $read = [];
        foreach ($this->readWatchers as $socketId => $watcher) {
            $read[$socketId] = $watcher['socket'];
        }

        $write = [];
        foreach ($this->writeWatchers as $socketId => $watcher) {
            $write[$socketId] = $watcher['socket'];
        }

        $except = null;

        if (empty($read) && empty($write)) {
            return false;
        }

        $numChanged = @stream_select($read, $write, $except, 0, 0);

        if ($numChanged === false || $numChanged === 0) {
            return false;
        }

        // $write now contains only the ready sockets, with keys preserved.
        foreach ($write as $socketId => $socket) {
            if (isset($this->writeWatchers[$socketId])) {
                $watcher = $this->writeWatchers[$socketId];
                unset($this->writeWatchers[$socketId]);
                ($watcher['callback'])();
            }
        }

        // $read now contains only the ready sockets, with keys preserved.
        foreach ($read as $socketId => $socket) {
            if (isset($this->readWatchers[$socketId])) {
                $watcher = $this->readWatchers[$socketId];
                // Do NOT unset the read watcher here unless it's a one-off read.
                // The read() operation in AsyncSocketOperations is one-shot, so it's
                // expecting its watcher to be removed.
                unset($this->readWatchers[$socketId]);
                ($watcher['callback'])();
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
