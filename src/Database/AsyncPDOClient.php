<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\AsyncPDOInterface;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;

// Define PDOSuccess if not already defined elsewhere
if (!class_exists('Rcalicdan\FiberAsync\Database\PDOSuccess')) {
    class PDOSuccess {}
}

class AsyncPDOClient implements AsyncPDOInterface
{
    private string $connectionId;
    private AsyncEventLoop $eventLoop;

    public function __construct()
    {
        $this->eventLoop = AsyncEventLoop::getInstance();
    }
    
    public function prepare(string $sql): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['prepare'] ?? 0;
        
        return Async::async(function () use ($sql, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($sql) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_PREPARE,
                    ['connId' => $this->connectionId, 'sql' => $sql],
                    function (?\Throwable $error, ?string $stmtId) use ($resolve, $reject) {
                        if ($error) { $reject($error); } else {
                            // UPDATED: Pass the connectionId along to the statement object
                            $resolve(new AsyncPDOStatement($this->connectionId, $stmtId, $this->eventLoop));
                        }
                    }
                );
            }));
        })();
    }

    // ... other methods (connect, query, transaction, etc.) remain unchanged ...
    public function connect(array $config): PromiseInterface
    {
        $latency = $this->eventLoop->getPDOLatencyConfig()['connect'] ?? 0;
        return Async::async(function () use ($config, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($config) {
                $operationId = $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_CONNECT,
                    $config,
                    function (?\Throwable $error, ?string $connId) use ($resolve, $reject) {
                        if ($error) {
                            $reject($error);
                        } else {
                            $this->connectionId = $connId;
                            $resolve($this);
                        }
                    }
                );
            }));
        })();
    }

    public function query(string $sql): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['query'] ?? 0;
        
        return Async::async(function () use ($sql, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($sql) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_QUERY,
                    ['connId' => $this->connectionId, 'sql' => $sql],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) { $reject($error); } else { $resolve($result); }
                    }
                );
            }));
        })();
    }
    
    public function beginTransaction(): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['transaction'] ?? 0;
        return Async::async(function () use ($latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_BEGIN,
                    ['connId' => $this->connectionId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve(new PDOSuccess());
                    }
                );
            }));
        })();
    }

    public function commit(): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['transaction'] ?? 0;
        return Async::async(function () use ($latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_COMMIT,
                    ['connId' => $this->connectionId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve(new PDOSuccess());
                    }
                );
            }));
        })();
    }

    public function rollback(): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['transaction'] ?? 0;
        return Async::async(function () use ($latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_ROLLBACK,
                    ['connId' => $this->connectionId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve(new PDOSuccess());
                    }
                );
            }));
        })();
    }

    public function lastInsertId(?string $name = null): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['overhead'] ?? 0;
        return Async::async(function () use ($name, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($name) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_LAST_INSERT_ID,
                    ['connId' => $this->connectionId, 'name' => $name],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve($result);
                    }
                );
            }));
        })();
    }

    public function close(): PromiseInterface
    {
        $this->ensureConnected();
        $latency = $this->eventLoop->getPDOLatencyConfig()['close'] ?? 0;
        return Async::async(function () use ($latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_CLOSE,
                    ['connId' => $this->connectionId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve(new PDOSuccess());
                    }
                );
            }));
        })();
    }

    private function ensureConnected(): void
    {
        if (!isset($this->connectionId)) {
            throw new \RuntimeException('Not connected to PDO. Call connect() first.');
        }
    }
}