<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Database\Contracts\DatabaseClientInterface;
use Rcalicdan\FiberAsync\Database\Exceptions\DatabaseException;

class MySQLClient implements DatabaseClientInterface
{
    private DatabaseConfig $config;
    private MySQLConnectionPool $connectionPool;
    private MySQLProtocol $protocol;
    private array $preparedStatements = [];
    private bool $inTransaction = false;
    private ?MySQLConnection $transactionConnection = null;
    private array $stats = [
        'queries_executed' => 0,
        'connections_created' => 0,
        'connections_reused' => 0,
        'errors' => 0,
        'total_time' => 0,
    ];

    public function __construct(DatabaseConfig $config)
    {
        $this->config = $config;
        $this->connectionPool = new MySQLConnectionPool($config);
        $this->protocol = new MySQLProtocol();
    }

    public function query(string $sql, array $params = []): PromiseInterface
    {
        $startTime = microtime(true);

        return new AsyncPromise(function ($resolve, $reject) use ($sql, $params, $startTime) {
            $this->getConnection()->then(function (MySQLConnection $connection) use ($sql, $params, $resolve, $reject, $startTime) {
                try {
                    if (empty($params)) {
                        $this->executeQuery($connection, $sql, $resolve, $reject, $startTime);
                    } else {
                        $this->executeParameterizedQuery($connection, $sql, $params, $resolve, $reject, $startTime);
                    }
                } catch (\Throwable $e) {
                    $this->handleError($e, $reject);
                    $this->releaseConnection($connection);
                }
            }, $reject);
        });
    }

    public function prepare(string $sql): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($sql) {
            $this->getConnection()->then(function (MySQLConnection $connection) use ($sql, $resolve, $reject) {
                try {
                    $preparePacket = $this->protocol->createPreparePacket($sql);

                    $connection->sendPacket($preparePacket)->then(function () use ($connection, $sql, $resolve, $reject) {
                        $connection->readPacket()->then(function ($response) use ($connection, $sql, $resolve, $reject) {
                            $result = $this->protocol->parseResult($response);

                            if ($result['type'] === 'error') {
                                $reject(new DatabaseException("Prepare failed: {$result['message']}"));
                            } else {
                                $statement = new MySQLPreparedStatement($connection, $sql, $result, $this->protocol);
                                $this->preparedStatements[] = $statement;
                                $resolve($statement);
                            }
                        }, $reject);
                    }, $reject);
                } catch (\Throwable $e) {
                    $this->handleError($e, $reject);
                    $this->releaseConnection($connection);
                }
            }, $reject);
        });
    }

    public function beginTransaction(): PromiseInterface
    {
        if ($this->inTransaction) {
            reject(new DatabaseException('Transaction already in progress'));
        }

        return $this->query('START TRANSACTION')->then(function ($result) {
            $this->inTransaction = true;
            return $result;
        });
    }

    public function commit(): PromiseInterface
    {
        if (!$this->inTransaction) {
            reject(new DatabaseException('No transaction in progress'));
        }

        return $this->query('COMMIT')->then(function ($result) {
            $this->inTransaction = false;
            $this->releaseTransactionConnection();
            return $result;
        });
    }

    public function rollback(): PromiseInterface
    {
        if (!$this->inTransaction) {
            reject(new DatabaseException('No transaction in progress'));
        }

        return $this->query('ROLLBACK')->then(function ($result) {
            $this->inTransaction = false;
            $this->releaseTransactionConnection();
            return $result;
        });
    }

    public function close(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            try {
                $this->connectionPool->closeAll();
                $this->preparedStatements = [];
                $this->inTransaction = false;
                $this->transactionConnection = null;
                $resolve(true);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->connectionPool->hasConnections();
    }

    public function getStats(): array
    {
        return array_merge($this->stats, $this->connectionPool->getStats());
    }

    private function getConnection(): PromiseInterface
    {
        if ($this->inTransaction && $this->transactionConnection) {
            resolve($this->transactionConnection);
        }

        return $this->connectionPool->getConnection()->then(function (MySQLConnection $connection) {
            if ($this->inTransaction) {
                $this->transactionConnection = $connection;
            }
            return $connection;
        });
    }

    private function releaseConnection(MySQLConnection $connection): void
    {
        if (!$this->inTransaction || $connection !== $this->transactionConnection) {
            $this->connectionPool->releaseConnection($connection);
        }
    }

    private function releaseTransactionConnection(): void
    {
        if ($this->transactionConnection) {
            $this->connectionPool->releaseConnection($this->transactionConnection);
            $this->transactionConnection = null;
        }
    }

    private function executeQuery(MySQLConnection $connection, string $sql, callable $resolve, callable $reject, float $startTime): void
    {
        $queryPacket = $this->protocol->createQueryPacket($sql);

        $connection->sendPacket($queryPacket)->then(function () use ($connection, $resolve, $reject, $startTime) {
            $connection->readPacket()->then(function ($response) use ($connection, $resolve, $reject, $startTime) {
                $result = $this->protocol->parseResult($response);

                if ($result['type'] === 'error') {
                    $this->handleError(new DatabaseException("Query failed: {$result['message']}"), $reject);
                } else {
                    $this->stats['queries_executed']++;
                    $this->stats['total_time'] += microtime(true) - $startTime;

                    if ($result['type'] === 'resultset') {
                        $this->fetchResultSet($connection, $result, $resolve, $reject);
                    } else {
                        $resolve($result);
                    }
                }

                $this->releaseConnection($connection);
            }, $reject);
        }, $reject);
    }

    private function executeParameterizedQuery(MySQLConnection $connection, string $sql, array $params, callable $resolve, callable $reject, float $startTime): void
    {
        $preparePacket = $this->protocol->createPreparePacket($sql);

        $connection->sendPacket($preparePacket)->then(function () use ($connection, $params, $resolve, $reject, $startTime) {
            $connection->readPacket()->then(function ($response) use ($connection, $params, $resolve, $reject, $startTime) {
                $result = $this->protocol->parseResult($response);

                if ($result['type'] === 'error') {
                    $this->handleError(new DatabaseException("Prepare failed: {$result['message']}"), $reject);
                } else {
                    $this->executeStatement($connection, $result, $params, $resolve, $reject, $startTime);
                }
            }, $reject);
        }, $reject);
    }

    private function executeStatement(MySQLConnection $connection, array $prepareResult, array $params, callable $resolve, callable $reject, float $startTime): void
    {
        $executePacket = $this->protocol->createExecutePacket($prepareResult['statement_id'], $params);

        $connection->sendPacket($executePacket)->then(function () use ($connection, $resolve, $reject, $startTime) {
            $connection->readPacket()->then(function ($response) use ($connection, $resolve, $reject, $startTime) {
                $result = $this->protocol->parseResult($response);

                if ($result['type'] === 'error') {
                    $this->handleError(new DatabaseException("Execute failed: {$result['message']}"), $reject);
                } else {
                    $this->stats['queries_executed']++;
                    $this->stats['total_time'] += microtime(true) - $startTime;

                    if ($result['type'] === 'resultset') {
                        $this->fetchResultSet($connection, $result, $resolve, $reject);
                    } else {
                        $resolve($result);
                    }
                }

                $this->releaseConnection($connection);
            }, $reject);
        }, $reject);
    }

    private function fetchResultSet(MySQLConnection $connection, array $result, callable $resolve, callable $reject): void
    {
        // This is a simplified implementation
        // In a complete implementation, you'd need to:
        // 1. Read column definition packets
        // 2. Read row data packets
        // 3. Handle different data types properly

        $resolve([
            'type' => 'select',
            'columns' => [],
            'rows' => [],
            'affected_rows' => 0,
            'insert_id' => 0,
        ]);
    }

    private function handleError(\Throwable $e, callable $reject): void
    {
        $this->stats['errors']++;
        $reject($e);
    }
}
