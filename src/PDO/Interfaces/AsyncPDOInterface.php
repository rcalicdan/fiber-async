<?php

namespace Rcalicdan\FiberAsync\PDO\Interfaces;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

interface AsyncPDOInterface
{
    public function connect(array $config): PromiseInterface;

    public function query(string $sql): PromiseInterface;

    public function prepare(string $sql): PromiseInterface;

    public function beginTransaction(): PromiseInterface;

    public function commit(): PromiseInterface;

    public function rollback(): PromiseInterface;

    public function lastInsertId(?string $name = null): PromiseInterface;

    public function close(): PromiseInterface;
}
