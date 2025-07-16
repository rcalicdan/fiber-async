<?php

namespace Rcalicdan\FiberAsync\Database;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\AsyncPDOStatementInterface;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\ValueObjects\PDOOperation;

class AsyncPDOStatement implements AsyncPDOStatementInterface
{
    private string $connectionId;
    private string $statementId;
    private AsyncEventLoop $eventLoop;

    public function __construct(string $connectionId, string $statementId, AsyncEventLoop $eventLoop)
    {
        $this->connectionId = $connectionId;
        $this->statementId = $statementId;
        $this->eventLoop = $eventLoop;
    }

    public function execute(array $params = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($params) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_EXECUTE,
                ['stmtId' => $this->statementId, 'params' => $params],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) {
                        $reject($error);
                    } else {
                        $resolve($result); // Result or OkPacket-like object
                    }
                }
            );
        });
    }

    public function fetchAll(int $fetchMode = \PDO::FETCH_ASSOC): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($fetchMode) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_FETCH_ALL,
                ['stmtId' => $this->statementId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) {
                        $reject($error);
                    } else {
                        $resolve($result);
                    }
                },
                ['fetchMode' => $fetchMode]
            );
        });
    }

    public function fetch(int $fetchMode = \PDO::FETCH_ASSOC): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($fetchMode) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_FETCH,
                ['stmtId' => $this->statementId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) {
                        $reject($error);
                    } else {
                        $resolve($result);
                    }
                },
                ['fetchMode' => $fetchMode]
            );
        });
    }

    public function rowCount(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_ROW_COUNT,
                ['stmtId' => $this->statementId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve($result);
                }
            );
        });
    }

    public function closeCursor(): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) {
            $this->eventLoop->addPDOOperation(
                PDOOperation::TYPE_CLOSE_CURSOR,
                ['stmtId' => $this->statementId],
                function (?\Throwable $error, mixed $result) use ($resolve, $reject) {
                    if ($error) $reject($error); else $resolve($result);
                }
            );
        });
    }

    // You might want a 'close' method here to explicitly close the statement,
    // though PDO statements are often garbage collected. For clarity, it's good.
    public function close(): PromiseInterface
    {
        // For PDO statements, closeCursor is usually sufficient.
        // A direct 'close statement' command might be specific to drivers.
        // For now, we'll rely on closeCursor and PHP's garbage collection for PDOStatement objects.
        return $this->closeCursor();
    }
}