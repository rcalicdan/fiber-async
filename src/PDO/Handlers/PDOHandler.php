<?php

namespace Rcalicdan\FiberAsync\PDO\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\AsyncPromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

final readonly class PDOHandler
{
    private EventLoop $eventLoop;

    public function __construct()
    {
        $this->eventLoop = EventLoop::getInstance();
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
