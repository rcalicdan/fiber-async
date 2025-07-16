<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\AsyncPDOInterface;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;

class PDOSuccess {}

class AsyncPDOClient implements AsyncPDOInterface
{
    private string $connectionId;
    private AsyncEventLoop $eventLoop;
    private AsyncOperations $asyncOps; // For async() and await()

    public function __construct()
    {
        $this->eventLoop = AsyncEventLoop::getInstance();
        $this->asyncOps = new AsyncOperations(); // Or use the Async facade
    }

    public function connect(array $config): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($config) {
            $operationId = $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_CONNECT,
                $config,
                function (?\Throwable $error, ?string $connId) use ($resolve, $reject) {
                    if ($error) {
                        $reject($error);
                    } else {
                        $this->connectionId = $connId;
                        $resolve($this); // Resolve with the client itself
                    }
                }
            );
            // Optionally, add a cancellation handler using $operationId
        });
    }

    public function query(string $sql): PromiseInterface
    {
        $this->ensureConnected();

        return new AsyncPromise(function ($resolve, $reject) use ($sql) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_QUERY,
                ['connId' => $this->connectionId, 'sql' => $sql],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) {
                        $reject($error);
                    } else {
                        $resolve($result); // Should be Result or PDOSuccess
                    }
                }
            );
        });
    }

    public function prepare(string $sql): PromiseInterface
    {
        $this->ensureConnected();

        return new AsyncPromise(function ($resolve, $reject) use ($sql) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_PREPARE,
                ['connId' => $this->connectionId, 'sql' => $sql],
                function (?\Throwable $error, ?string $stmtId) use ($resolve, $reject) {
                    if ($error) {
                        $reject($error);
                    } else {
                        $resolve(new AsyncPDOStatement($this->connectionId, $stmtId, $this->eventLoop));
                    }
                }
            );
        });
    }

    public function beginTransaction(): PromiseInterface
    {
        $this->ensureConnected();
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_BEGIN,
                ['connId' => $this->connectionId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve(new PDOSuccess());
                }
            );
        });
    }

    public function commit(): PromiseInterface
    {
        $this->ensureConnected();
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_COMMIT,
                ['connId' => $this->connectionId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve(new PDOSuccess());
                }
            );
        });
    }

    public function rollback(): PromiseInterface
    {
        $this->ensureConnected();
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_ROLLBACK,
                ['connId' => $this->connectionId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve(new PDOSuccess());
                }
            );
        });
    }

    public function lastInsertId(?string $name = null): PromiseInterface
    {
        $this->ensureConnected();
        return new AsyncPromise(function ($resolve, $reject) use ($name) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_LAST_INSERT_ID,
                ['connId' => $this->connectionId, 'name' => $name],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve($result);
                }
            );
        });
    }

    public function close(): PromiseInterface
    {
        $this->ensureConnected();
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_CLOSE,
                ['connId' => $this->connectionId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve(new PDOSuccess());
                }
            );
        });
    }

    private function ensureConnected(): void
    {
        if (!isset($this->connectionId)) {
            throw new \RuntimeException('Not connected to PDO. Call connect() first.');
        }
    }
}