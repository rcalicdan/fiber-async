<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\AsyncPDOStatementInterface;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;

class AsyncPDOStatement implements AsyncPDOStatementInterface
{
    private string $connectionId; 
    private string $statementId;
    private AsyncEventLoop $eventLoop;

    // RE-ADDED $connectionId to the constructor
    public function __construct(string $connectionId, string $statementId, AsyncEventLoop $eventLoop)
    {
        $this->connectionId = $connectionId;
        $this->statementId = $statementId;
        $this->eventLoop = $eventLoop;
    }

    public function execute(array $params = []): PromiseInterface
    {
        $latency = $this->eventLoop->getPDOLatencyConfig()['execute'] ?? 0;
        return Async::async(function () use ($params, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($params) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_EXECUTE,
                    ['stmtId' => $this->statementId, 'params' => $params],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) { $reject($error); } else { $resolve($result); }
                    }
                );
            }));
        })();
    }

    // ... other methods (fetchAll, fetch, rowCount, closeCursor) remain the same ...
    public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): PromiseInterface
    {
        $latency = $this->eventLoop->getPDOLatencyConfig()['fetch'] ?? 0;
        return Async::async(function () use ($fetchMode, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($fetchMode) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_FETCH_ALL,
                    ['stmtId' => $this->statementId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) { $reject($error); } else { $resolve($result); }
                    },
                    ['fetchMode' => $fetchMode]
                );
            }));
        })();
    }

    public function fetch(int $fetchMode = \PDO::FETCH_ASSOC): PromiseInterface
    {
        $latency = $this->eventLoop->getPDOLatencyConfig()['fetch'] ?? 0;
        return Async::async(function () use ($fetchMode, $latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) use ($fetchMode) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_FETCH,
                    ['stmtId' => $this->statementId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) { $reject($error); } else { $resolve($result); }
                    },
                    ['fetchMode' => $fetchMode]
                );
            }));
        })();
    }

    public function rowCount(): PromiseInterface
    {
        $latency = $this->eventLoop->getPDOLatencyConfig()['overhead'] ?? 0;
        return Async::async(function () use ($latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_ROW_COUNT,
                    ['stmtId' => $this->statementId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve($result);
                    }
                );
            }));
        })();
    }

    public function closeCursor(): PromiseInterface
    {
        $latency = $this->eventLoop->getPDOLatencyConfig()['overhead'] ?? 0;
        return Async::async(function () use ($latency) {
            await(Async::delay($latency / 1000));
            return await(new AsyncPromise(function ($resolve, $reject) {
                $this->eventLoop->addPDOOperation(
                    PDOOperation::TYPE_CLOSE_CURSOR,
                    ['stmtId' => $this->statementId],
                    function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                        if ($error) $reject($error); else $resolve($result);
                    }
                );
            }));
        })();
    }
    
    public function close(): PromiseInterface
    {
        return $this->closeCursor();
    }
}