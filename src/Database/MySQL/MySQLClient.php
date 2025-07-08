<?php
// src/Database/MySQL/MySQLClient.php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Config\DatabaseConfig;
use Rcalicdan\FiberAsync\Contracts\Database\DatabaseClientInterface;
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

        return $this->getConnection()->then(function (MySQLConnection $connection) use ($sql, $params, $startTime) {
            $releaseConnection = !$this->inTransaction;

            if (empty($params)) {
                return $this->executeQuery($connection, $sql, $startTime, $releaseConnection);
            } else {
                return $this->executeParameterizedQuery($connection, $sql, $params, $startTime, $releaseConnection);
            }
        });
    }

    public function prepare(string $sql): PromiseInterface
    {
        $trimmedSql = trim(strtoupper($sql));
        $nonPreparableStatements = ['SHOW', 'USE', 'SET', 'DESCRIBE', 'DESC', 'EXPLAIN'];
        $firstWord = explode(' ', $trimmedSql)[0];

        if (in_array($firstWord, $nonPreparableStatements)) {
            return reject(new DatabaseException("Statement cannot be prepared: {$sql}"));
        }

        return $this->getConnection()->then(function (MySQLConnection $connection) use ($sql) {
            return $connection->sendPacket($this->protocol->createPreparePacket($sql))
                ->then(function () use ($connection) {
                    return $connection->readPacket();
                })
                ->then(function ($response) use ($connection, $sql) {
                    $prepareResult = $this->protocol->parsePrepareResponse($response);

                    if ($prepareResult['type'] === 'error') {
                        throw new DatabaseException("Prepare failed: {$prepareResult['message']}");
                    }

                    $paramCount = $prepareResult['param_count'];
                    $columnCount = $prepareResult['column_count'];

                    $promise = resolve(true);

                    // Read parameter definition packets if any
                    if ($paramCount > 0) {
                        $promise = $promise->then(function () use ($connection, $paramCount) {
                            return $this->readAndDiscardPackets($connection, $paramCount);
                        })->then(function () use ($connection) {
                            return $connection->readPacket(); // EOF packet
                        });
                    }

                    // Read column definition packets if any
                    if ($columnCount > 0) {
                        $promise = $promise->then(function () use ($connection, $columnCount) {
                            return $this->readAndDiscardPackets($connection, $columnCount);
                        })->then(function () use ($connection) {
                            return $connection->readPacket(); // EOF packet
                        });
                    }

                    return $promise->then(function () use ($connection, $sql, $prepareResult) {
                        $statement = new MySQLPreparedStatement($connection, $sql, $prepareResult, $this->protocol);
                        $this->preparedStatements[spl_object_hash($statement)] = $connection;
                        return $statement;
                    });
                });
        });
    }

    public function beginTransaction(): PromiseInterface
    {
        if ($this->inTransaction) {
            return reject(new DatabaseException('Transaction already in progress'));
        }

        return $this->connectionPool->getConnection()->then(function (MySQLConnection $connection) {
            $this->inTransaction = true;
            $this->transactionConnection = $connection;

            return $this->query('START TRANSACTION')->catch(function ($error) {
                $this->inTransaction = false;
                $this->releaseTransactionConnection();
                throw $error;
            });
        });
    }

    public function commit(): PromiseInterface
    {
        if (!$this->inTransaction) {
            return reject(new DatabaseException('No transaction in progress'));
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
            return reject(new DatabaseException('No transaction in progress'));
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
            return resolve($this->transactionConnection);
        }

        return $this->connectionPool->getConnection();
    }

    private function releaseConnection(MySQLConnection $connection): void
    {
        if ($this->inTransaction && $connection === $this->transactionConnection) {
            return;
        }
        if (in_array($connection, $this->preparedStatements, true)) {
            return;
        }
        $this->connectionPool->releaseConnection($connection);
    }

    private function releaseTransactionConnection(): void
    {
        if ($this->transactionConnection) {
            $this->connectionPool->releaseConnection($this->transactionConnection);
            $this->transactionConnection = null;
        }
    }

    private function executeQuery(MySQLConnection $connection, string $sql, float $startTime, bool $releaseConnection): PromiseInterface
    {
        $queryPacket = $this->protocol->createQueryPacket($sql);

        return $connection->sendPacket($queryPacket)
            ->then(function () use ($connection) {
                return $connection->readPacket();
            })
            ->then(function ($response) use ($connection, $sql, $startTime, $releaseConnection) {
                $result = $this->protocol->parseResult($response);

                if ($result['type'] === 'error') {
                    throw new DatabaseException("Query failed: {$result['message']}");
                }

                $this->stats['queries_executed']++;
                $this->stats['total_time'] += microtime(true) - $startTime;

                if ($result['type'] === 'resultset') {
                    return $this->fetchFullResultSet($connection, $result, $releaseConnection);
                } else {
                    // This is an OK packet (INSERT, UPDATE, DELETE, etc.)
                    if ($releaseConnection) {
                        $this->releaseConnection($connection);
                    }
                    return $result;
                }
            })
            ->catch(function ($error) use ($connection, $releaseConnection) {
                if ($releaseConnection) {
                    $this->releaseConnection($connection);
                }
                $this->stats['errors']++;
                throw $error;
            });
    }

    private function executeParameterizedQuery(MySQLConnection $connection, string $sql, array $params, float $startTime, bool $releaseConnection): PromiseInterface
    {
        try {
            $finalSql = $this->interpolateQuery($sql, $params);
            return $this->executeQuery($connection, $finalSql, $startTime, $releaseConnection);
        } catch (\Throwable $e) {
            if ($releaseConnection) {
                $this->releaseConnection($connection);
            }
            return reject($e);
        }
    }

    private function interpolateQuery(string $sql, array $params): string
    {
        $i = 0;
        $sql = preg_replace_callback('/\?/', function ($matches) use ($params, &$i) {
            if (!isset($params[$i])) {
                throw new DatabaseException("Not enough parameters for query");
            }
            $param = $params[$i++];
            return $this->escapeAndQuote($param);
        }, $sql);

        return $sql;
    }

    private function escapeAndQuote($param): string
    {
        if ($param === null) {
            return 'NULL';
        }
        if (is_int($param) || is_float($param)) {
            return (string)$param;
        }
        $escaped = addslashes((string)$param);
        return "'{$escaped}'";
    }

    private function fetchFullResultSet(MySQLConnection $connection, array $initialResult, bool $releaseConnection): PromiseInterface
    {
        $columnCount = $initialResult['column_count'];
        $columns = [];
        $rows = [];

        return $this->readNPackets($connection, $columnCount, $columns)
            ->then(function () use ($connection) {
                // Read the EOF packet after column definitions
                return $connection->readPacket();
            })
            ->then(function () use ($connection, &$rows, &$columns, $releaseConnection) {
                return $this->readAllRows($connection, $rows, $columns);
            })
            ->then(function () use (&$rows, &$columns, $connection, $releaseConnection) {
                // FIX: Always ensure 'rows' key exists
                $finalResult = [
                    'type' => 'select',
                    'columns' => array_map(fn($col) => $col['name'], $columns),
                    'rows' => $rows ?? [], // Ensure rows is always an array
                ];

                if ($releaseConnection) {
                    $this->releaseConnection($connection);
                }

                return $finalResult;
            })
            ->catch(function ($error) use ($connection, $releaseConnection) {
                if ($releaseConnection) {
                    $this->releaseConnection($connection);
                }
                throw $error;
            });
    }

    private function readAllRows(MySQLConnection $connection, array &$rows, array &$columns): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($connection, &$rows, &$columns) {
            $readNextRow = function () use (&$readNextRow, $connection, &$rows, &$columns, $resolve, $reject) {
                $connection->readPacket()->then(
                    function ($packetData) use (&$readNextRow, &$rows, &$columns, $resolve) {
                        if ($this->protocol->isEofPacket($packetData)) {
                            $resolve(true);
                            return;
                        }

                        $rows[] = $this->protocol->parseRowData($packetData, $columns);
                        $readNextRow();
                    },
                    $reject
                );
            };
            $readNextRow();
        });
    }

    private function readNPackets(MySQLConnection $connection, int $count, array &$results = []): PromiseInterface
    {
        if ($count <= 0) {
            return resolve(true);
        }

        return new AsyncPromise(function ($resolve, $reject) use ($connection, $count, &$results) {
            $readNext = function () use (&$readNext, $connection, $count, &$results, $resolve, $reject) {
                if (count($results) >= $count) {
                    $resolve(true);
                    return;
                }

                $connection->readPacket()->then(function ($packetData) use (&$readNext, &$results) {
                    $results[] = $this->protocol->parseColumnDefinition($packetData);
                    $readNext();
                }, $reject);
            };
            $readNext();
        });
    }

    private function readAndDiscardPackets(MySQLConnection $connection, int $count): PromiseInterface
    {
        if ($count <= 0) {
            return resolve(true);
        }

        return new AsyncPromise(function ($resolve, $reject) use ($connection, $count) {
            $readCount = 0;
            $readNext = function () use (&$readNext, $connection, $count, &$readCount, $resolve, $reject) {
                if ($readCount >= $count) {
                    $resolve(true);
                    return;
                }

                $connection->readPacket()->then(function ($packet) use (&$readNext, &$readCount) {
                    $readCount++;
                    $readNext();
                }, $reject);
            };

            $readNext();
        });
    }
}