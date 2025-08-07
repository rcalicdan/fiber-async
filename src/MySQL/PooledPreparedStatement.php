<?php

namespace Rcalicdan\FiberAsync\MySQL;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A prepared statement wrapper that manages connection pooling.
 */
class PooledPreparedStatement
{
    private PreparedStatement $statement;
    private MySQLClient $client;
    private MySQLPool $pool;
    private bool $released = false;

    public function __construct(PreparedStatement $statement, MySQLClient $client, MySQLPool $pool)
    {
        $this->statement = $statement;
        $this->client = $client;
        $this->pool = $pool;
    }

    public function execute(array $params = []): PromiseInterface
    {
        return async(function () use ($params) {
            try {
                return await($this->statement->execute($params));
            } catch (\Throwable $e) {
                $this->releaseConnection();

                throw $e;
            }
        })();
    }

    public function close(): PromiseInterface
    {
        return async(function () {
            try {
                await($this->statement->close());
            } finally {
                $this->releaseConnection();
            }
        })();
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
