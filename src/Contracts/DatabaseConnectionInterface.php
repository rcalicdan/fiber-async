<?php

namespace Rcalicdan\FiberAsync\Contracts;

interface DatabaseConnectionInterface
{
    public function connect(): bool;
    public function disconnect(): void;
    public function isConnected(): bool;
    // public function query(string $sql, array $bindings = []): mixed;
    public function asyncQuery(string $sql, array $bindings, callable $onSuccess, callable $onFailure): void;
    public function prepare(string $sql): mixed;
    public function execute(mixed $statement, array $bindings = []): mixed;
    public function fetchAll(mixed $result): array;
    public function fetchOne(mixed $result): ?array;
    public function getLastInsertId(): int;
    public function getAffectedRows(): int;
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollback(): bool;
}
