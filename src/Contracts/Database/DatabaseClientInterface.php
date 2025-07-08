<?php
// src/Database/Contracts/DatabaseClientInterface.php

namespace Rcalicdan\FiberAsync\Database\Contracts;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

interface DatabaseClientInterface
{
    /**
     * Execute a query with optional parameters
     */
    public function query(string $sql, array $params = []): PromiseInterface;

    /**
     * Execute a prepared statement
     */
    public function prepare(string $sql): PromiseInterface;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): PromiseInterface;

    /**
     * Commit a transaction
     */
    public function commit(): PromiseInterface;

    /**
     * Rollback a transaction
     */
    public function rollback(): PromiseInterface;

    /**
     * Close the connection
     */
    public function close(): PromiseInterface;

    /**
     * Check if connected
     */
    public function isConnected(): bool;

    /**
     * Get connection statistics
     */
    public function getStats(): array;
}