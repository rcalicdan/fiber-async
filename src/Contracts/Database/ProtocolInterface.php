<?php
// src/Database/Contracts/ProtocolInterface.php

namespace Rcalicdan\FiberAsync\Database\Contracts;

interface ProtocolInterface
{
    /**
     * Parse handshake packet
     */
    public function parseHandshake(string $data): array;

    /**
     * Create authentication packet
     */
    public function createAuthPacket(string $username, string $password, string $database, array $handshake): string;

    /**
     * Create query packet
     */
    public function createQueryPacket(string $sql): string;

    /**
     * Create prepared statement packet
     */
    public function createPreparePacket(string $sql): string;

    /**
     * Create execute packet
     */
    public function createExecutePacket(int $statementId, array $params): string;

    /**
     * Parse result packet
     */
    public function parseResult(string $data): array;

    /**
     * Parse error packet
     */
    public function parseError(string $data): array;
}