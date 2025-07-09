<?php

namespace Rcalicdan\FiberAsync\Managers;

class SocketManager
{
    private array $readQueues = [];
    private array $writeQueues = [];

    public function addReadWatcher($socket, callable $callback): void
    {
        $socketId = (int) $socket;
        // Ensure a queue exists for this socket
        if (! isset($this->readQueues[$socketId])) {
            $this->readQueues[$socketId] = [
                'socket' => $socket,
                'callbacks' => [],
            ];
        }
        // Add the new callback to the queue for this socket
        $this->readQueues[$socketId]['callbacks'][] = $callback;
    }

    public function addWriteWatcher($socket, callable $callback): void
    {
        $socketId = (int) $socket;
        // Ensure a queue exists for this socket
        if (! isset($this->writeQueues[$socketId])) {
            $this->writeQueues[$socketId] = [
                'socket' => $socket,
                'callbacks' => [],
            ];
        }
        // Add the new callback to the queue for this socket
        $this->writeQueues[$socketId]['callbacks'][] = $callback;
    }

    public function removeReadWatcher($socket): void
    {
        unset($this->readQueues[(int) $socket]);
    }

    public function removeWriteWatcher($socket): void
    {
        unset($this->writeQueues[(int) $socket]);
    }

    public function processSockets(): bool
    {
        if (empty($this->readQueues) && empty($this->writeQueues)) {
            return false;
        }

        $readSockets = array_column($this->readQueues, 'socket');
        $writeSockets = array_column($this->writeQueues, 'socket');

        if (! $readSockets && ! $writeSockets) {
            return false;
        }

        $except = null;
        // Use a small timeout to prevent busy-looping on the CPU
        $numChanged = @stream_select($readSockets, $writeSockets, $except, 0, 1000);

        if ($numChanged === false || $numChanged === 0) {
            return false;
        }

        // --- THE CRITICAL FIX ---
        // For each socket that is ready, execute ALL pending callbacks in its queue.

        foreach ($writeSockets as $socket) {
            $socketId = (int) $socket;
            if (isset($this->writeQueues[$socketId])) {
                $queue = $this->writeQueues[$socketId]['callbacks'];
                unset($this->writeQueues[$socketId]); // The entire queue is processed now
                foreach ($queue as $callback) {
                    try {
                        $callback();
                    } catch (\Throwable $e) { /* Log error if necessary */
                    }
                }
            }
        }

        foreach ($readSockets as $socket) {
            $socketId = (int) $socket;
            if (isset($this->readQueues[$socketId])) {
                $queue = $this->readQueues[$socketId]['callbacks'];
                unset($this->readQueues[$socketId]); // The entire queue is processed now
                foreach ($queue as $callback) {
                    try {
                        $callback();
                    } catch (\Throwable $e) { /* Log error if necessary */
                    }
                }
            }
        }

        return true;
    }

    public function hasWatchers(): bool
    {
        return ! empty($this->readQueues) || ! empty($this->writeQueues);
    }

    public function clearAllWatchersForSocket($socket): void
    {
        $this->removeReadWatcher($socket);
        $this->removeWriteWatcher($socket);
    }
}
