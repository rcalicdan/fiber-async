<?php
// src/Database/MySQL/MySQLPreparedStatement.php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\Exceptions\DatabaseException;

class MySQLPreparedStatement
{
    private MySQLConnection $connection;
    private string $sql;
    private array $prepareResult;
    private MySQLProtocol $protocol;

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
                
                $this->connection->sendPacket($executePacket)->then(function () use ($resolve, $reject) {
                    $this->connection->readPacket()->then(function ($response) use ($resolve, $reject) {
                        $result = $this->protocol->parseResult($response);
                        
                        if ($result['type'] === 'error') {
                            $reject(new DatabaseException("Execute failed: {$result['message']}"));
                        } else {
                            $resolve($result);
                        }
                    }, $reject);
                }, $reject);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }

    public function close(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            // Send close statement packet
            $resolve(true);
        });
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}