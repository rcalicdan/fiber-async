<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Exceptions\DatabaseException;
use Rcalicdan\FiberAsync\Facades\Async;

class MySQLPreparedStatement
{
    private MySQLConnection $connection;
    private string $sql;
    private array $prepareResult;
    private MySQLProtocol $protocol;
    private array $columns = [];

    public function __construct(MySQLConnection $connection, string $sql, array $prepareResult, MySQLProtocol $protocol)
    {
        $this->connection = $connection;
        $this->sql = $sql;
        $this->prepareResult = $prepareResult;
        $this->protocol = $protocol;
    }

    public function execute(array $params = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($params) {
            try {
                $executePacket = $this->protocol->createExecutePacket($this->prepareResult['statement_id'], $params);

                $this->connection->sendPacket($executePacket)
                    ->then(function () {
                        return $this->connection->readPacket();
                    })
                    ->then(function ($response) {
                        $initialResult = $this->protocol->parseResult($response);

                        if ($initialResult['type'] === 'error') {
                            throw new DatabaseException("Execute failed: {$initialResult['message']}");
                        }

                        if ($initialResult['type'] === 'resultset') {
                            return $this->fetchBinaryResultSet($initialResult);
                        }

                        return $initialResult;
                    })
                    ->then($resolve, $reject);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    private function fetchBinaryResultSet(array $initialResult): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($initialResult) {
            $columnCount = $initialResult['column_count'];
            $this->columns = [];
            $rows = [];

            $this->readNPackets($columnCount, $this->columns)
                ->then(function () {
                    // Read EOF packet after columns
                    return $this->connection->readPacket();
                })
                ->then(function () use (&$rows) {
                    // Read all row data packets
                    return $this->readAllRows($rows);
                })
                ->then(function () use (&$rows) {
                    return [
                        'type' => 'select',
                        'columns' => array_map(fn($col) => $col['name'], $this->columns),
                        'rows' => $rows,
                    ];
                })
                ->then($resolve, $reject);
        });
    }

    private function readAllRows(array &$rows): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use (&$rows) {
            $readNextRow = function () use (&$readNextRow, &$rows, $resolve, $reject) {
                $this->connection->readPacket()
                    ->then(function ($packetData) use (&$readNextRow, &$rows, $resolve, $reject) {
                        if ($this->protocol->isEofPacket($packetData)) {
                            $resolve(true);
                            return;
                        }

                        $rows[] = $this->protocol->parseBinaryRow($packetData, $this->columns);
                        $readNextRow();
                    }, $reject);
            };
            
            $readNextRow();
        });
    }

    private function readNPackets(int $count, array &$results = []): PromiseInterface
    {
        if ($count <= 0) {
            return Async::resolve(true);
        }

        return new AsyncPromise(function ($resolve, $reject) use ($count, &$results) {
            $readNext = function () use (&$readNext, $count, &$results, $resolve, $reject) {
                if (count($results) >= $count) {
                    $resolve(true);
                    return;
                }

                $this->connection->readPacket()
                    ->then(function ($packetData) use (&$readNext, &$results) {
                        $results[] = $this->protocol->parseColumnDefinition($packetData);
                        $readNext();
                    }, $reject);
            };
            
            $readNext();
        });
    }

    public function close(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve) {
            // Send COM_STMT_CLOSE packet in production
            $resolve(true);
        });
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}