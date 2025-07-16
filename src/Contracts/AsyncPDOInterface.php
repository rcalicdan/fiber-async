<?php

namespace Rcalicdan\FiberAsync\Contracts;

use Rcalicdan\FiberAsync\Database\AsyncPDOStatement;
use Rcalicdan\FiberAsync\Database\Result; // Re-use the existing Result object

interface AsyncPDOInterface
{
    public function connect(array $config): PromiseInterface;
    public function query(string $sql): PromiseInterface; // Returns Promise<Result|OkPacket>
    public function prepare(string $sql): PromiseInterface; // Returns Promise<AsyncPDOStatement>
    public function beginTransaction(): PromiseInterface;
    public function commit(): PromiseInterface;
    public function rollback(): PromiseInterface;
    public function lastInsertId(?string $name = null): PromiseInterface; // Returns Promise<string>
    public function close(): PromiseInterface;
}