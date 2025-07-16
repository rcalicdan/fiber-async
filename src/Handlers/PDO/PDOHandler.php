<?php

namespace Rcalicdan\FiberAsync\Handlers\PDO;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

final readonly class PDOHandler
{
    private AsyncEventLoop $eventLoop;

    public function __construct()
    {
        $this->eventLoop = AsyncEventLoop::getInstance();
    }

    public function query(string $sql, array $params = [], array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($sql, $params, $options) {
            $this->eventLoop->addPDOOperation(
                'query',
                ['sql' => $sql, 'params' => $params],
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function execute(string $sql, array $params = [], array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($sql, $params, $options) {
            $this->eventLoop->addPDOOperation(
                'execute',
                ['sql' => $sql, 'params' => $params],
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function prepare(string $sql, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($sql, $options) {
            $this->eventLoop->addPDOOperation(
                'prepare',
                ['sql' => $sql],
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function beginTransaction(array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($options) {
            $this->eventLoop->addPDOOperation(
                'beginTransaction',
                [],
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function commit(array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($options) {
            $this->eventLoop->addPDOOperation(
                'commit',
                [],
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }

    public function rollback(array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($options) {
            $this->eventLoop->addPDOOperation(
                'rollback',
                [],
                function (?string $error, mixed $result = null) use ($resolve, $reject) {
                    if ($error) {
                        $reject(new \RuntimeException($error));
                    } else {
                        $resolve($result);
                    }
                },
                $options
            );
        });
    }
}