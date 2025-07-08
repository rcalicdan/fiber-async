<?php

namespace Rcalicdan\FiberAsync\Database\MySQL;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\MysqlConfig;
use Rcalicdan\FiberAsync\Database\MySQL\ValueObjects\PendingCommand;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Handlers\Mysql\MysqlProtocolHandler;

class Pool
{
    private array $idleConnections = [];
    private array $busyConnections = [];
    private array $pendingAcquires = [];
    private int $connectionCount = 0;

    public function __construct(
        private readonly MysqlConfig $config,
        private readonly int $maxConnections = 10
    ) {
    }

    public function query(string $sql): PromiseInterface
    {
        return $this->runCommand(new PendingCommand(PendingCommand::TYPE_QUERY, $sql));
    }

    public function prepare(string $sql): PromiseInterface
    {
        return $this->runCommand(new PendingCommand(PendingCommand::TYPE_PREPARE, $sql));
    }

    public function execute(int $statementId, array $params, bool $hasRows): PromiseInterface
    {
        return $this->runCommand(new PendingCommand(PendingCommand::TYPE_EXECUTE, ['id' => $statementId, 'params' => $params, 'hasRows' => $hasRows]));
    }

    public function closeStatement(int $statementId): PromiseInterface
    {
        return $this->runCommand(new PendingCommand(PendingCommand::TYPE_CLOSE_STMT, $statementId));
    }

    public function beginTransaction(): PromiseInterface
    {
        return new \Rcalicdan\FiberAsync\AsyncPromise(function($resolve, $reject) {
            $this->acquireConnection(true)->then(
                function (Connection $conn) use ($resolve, $reject) {
                    $conn->enqueue(new PendingCommand(PendingCommand::TYPE_QUERY, 'BEGIN'))->then(
                        fn() => $resolve(new Transaction($this, $conn)),
                        function($err) use ($conn, $reject) {
                            $this->releaseConnection($conn);
                            $reject($err);
                        }
                    );
                },
                $reject
            );
        });
    }

    private function runCommand(PendingCommand $command): PromiseInterface
    {
        return new \Rcalicdan\FiberAsync\AsyncPromise(function ($resolve, $reject) use ($command) {
            $this->acquireConnection()->then(
                function (Connection $conn) use ($command, $resolve, $reject) {
                    $conn->enqueue($command)->then(
                        function ($result) use ($conn, $resolve) {
                            $this->releaseConnection($conn);
                            $resolve($result);
                        },
                        function ($error) use ($conn, $reject) {
                            $this->releaseConnection($conn, true);
                            $reject($error);
                        }
                    );
                },
                $reject
            );
        });
    }

    private function acquireConnection(bool $exclusive = false): PromiseInterface
    {
        if (!$exclusive && !empty($this->idleConnections)) {
            $connection = array_pop($this->idleConnections);
            $this->busyConnections[spl_object_hash($connection)] = $connection;
            return Async::resolve($connection);
        }

        if ($this->connectionCount < $this->maxConnections) {
            $this->connectionCount++;
            $connection = $this->createConnection();
            
            return new \Rcalicdan\FiberAsync\AsyncPromise(function ($resolve, $reject) use ($connection) {
                $connection->connect()->then(
                    function($conn) use ($resolve) {
                        $this->busyConnections[spl_object_hash($conn)] = $conn;
                        $resolve($conn);
                    },
                    function($err) use ($reject) {
                        $this->connectionCount--;
                        $reject($err);
                    }
                );
            });
        }

        $promise = new \Rcalicdan\FiberAsync\AsyncPromise();
        $this->pendingAcquires[] = $promise;
        return $promise;
    }
    
    public function releaseConnection(Connection $connection, bool $forceClose = false): void
    {
        $hash = spl_object_hash($connection);
        if (!isset($this->busyConnections[$hash])) return;
        
        unset($this->busyConnections[$hash]);

        if ($forceClose || $connection->state === Connection::STATE_DISCONNECTED) {
            $connection->close();
            $this->connectionCount--;
        }

        if (!empty($this->pendingAcquires)) {
            $promise = array_shift($this->pendingAcquires);
            $this->busyConnections[$hash] = $connection;
            $promise->resolve($connection);
        } elseif (!$forceClose) {
            $this->idleConnections[] = $connection;
        }
    }

    private function createConnection(): Connection
    {
        return new Connection(
            $this,
            $this->config,
            AsyncEventLoop::getInstance(),
            new MysqlProtocolHandler()
        );
    }

    public function close(): void
    {
        foreach ($this->idleConnections as $conn) $conn->close();
        foreach ($this->busyConnections as $conn) $conn->close();
        foreach ($this->pendingAcquires as $promise) $promise->reject(new Exception("Pool is closing"));
        $this->idleConnections = [];
        $this->busyConnections = [];
        $this->pendingAcquires = [];
        $this->connectionCount = 0;
    }
}