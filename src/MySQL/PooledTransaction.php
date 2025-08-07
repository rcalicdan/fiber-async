<?php

namespace Rcalicdan\FiberAsync\MySQL;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A transaction wrapper that manages connection pooling.
 */
class PooledTransaction
{
    private MySQLClient $client;
    private MySQLPool $pool;
    private bool $released = false;

    public function __construct(MySQLClient $client, MySQLPool $pool)
    {
        $this->client = $client;
        $this->pool = $pool;
    }

    public function query(string $sql): PromiseInterface
    {
        return $this->client->query($sql);
    }

    public function prepare(string $sql): PromiseInterface
    {
        return $this->client->prepare($sql);
    }

    public function commit(): PromiseInterface
    {
        return async(function () {
            try {
                $result = await($this->client->commit());

                return $result;
            } finally {
                $this->releaseConnection();
            }
        })();
    }

    public function rollback(): PromiseInterface
    {
        return async(function () {
            try {
                $result = await($this->client->rollback());

                return $result;
            } finally {
                $this->releaseConnection();
            }
        })();
    }

    public function savepoint(string $name): PromiseInterface
    {
        return $this->client->savepoint($name);
    }

    public function rollbackToSavepoint(string $name): PromiseInterface
    {
        return $this->client->rollbackToSavepoint($name);
    }

    public function releaseSavepoint(string $name): PromiseInterface
    {
        return $this->client->releaseSavepoint($name);
    }

    private function releaseConnection(): void
    {
        if (! $this->released) {
            $this->pool->release($this->client);
            $this->released = true;
        }
    }

    public function __destruct()
    {
        $this->releaseConnection();
    }
}
