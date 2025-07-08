<?php

namespace Rcalicdan\FiberAsync\Database\Contracts;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

interface ConnectionInterface
{
    /**
     * Connect to the database
     */
    public function connect(): PromiseInterface;

    /**
     * Send a packet to the server
     */
    public function sendPacket(string $data): PromiseInterface;

    /**
     * Read a packet from the server
     */
    public function readPacket(): PromiseInterface;

    /**
     * Close the connection
     */
    public function close(): void;

    /**
     * Check if connected
     */
    public function isConnected(): bool;

    /**
     * Get the socket resource
     */
    public function getSocket();
}