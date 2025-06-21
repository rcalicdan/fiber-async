<?php

namespace Rcalicdan\FiberAsync;

use Exception;
use Throwable;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class LoopManager
{
    protected static ?self $instance = null;
    protected AsyncEventLoop $eventLoop;
    protected AsyncManager $asyncManager;

    protected function __construct()
    {
        // Lazy initialization
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function getEventLoop(): AsyncEventLoop
    {
        if ($this->eventLoop === null) {
            $this->eventLoop = AsyncEventLoop::getInstance();
        }
        return $this->eventLoop;
    }

    protected function getAsyncManager(): AsyncManager
    {
        if ($this->asyncManager === null) {
            $this->asyncManager = AsyncManager::getInstance();
        }
        return $this->asyncManager;
    }

    public function run(callable|PromiseInterface $asyncOperation): mixed
    {
        $result = null;
        $error = null;
        $completed = false;

        $promise = is_callable($asyncOperation)
            ? $this->getAsyncManager()->async($asyncOperation)()
            : $asyncOperation;

        $promise
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            })
            ->catch(function ($reason) use (&$error, &$completed) {
                $error = $reason;
                $completed = true;
            });

        while (!$completed && !$this->eventLoop->isIdle()) {
            $this->getEventLoop()->run();
            if ($completed) {
                break;
            }
            usleep(1000);
        }

        if ($error !== null) {
            throw $error instanceof Throwable ? $error : new Exception((string) $error);
        }

        return $result;
    }

    public function runAll(array $asyncOperations): array
    {
        return $this->run(function () use ($asyncOperations) {
            $promises = [];

            foreach ($asyncOperations as $key => $operation) {
                if (is_callable($operation)) {
                    $promises[$key] = $this->getAsyncManager()->async($operation)();
                } else {
                    $promises[$key] = $operation;
                }
            }

            return $this->getAsyncManager()->await($this->getAsyncManager()->all($promises));
        });
    }

    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return $this->run(function () use ($asyncOperations, $concurrency) {
            return $this->getAsyncManager()->await($this->getAsyncManager()->concurrent($asyncOperations, $concurrency));
        });
    }

    public function task(callable $asyncFunction): mixed
    {
        return $this->run($this->getAsyncManager()->async($asyncFunction)());
    }

    public function quickFetch(string $url, array $options = []): array
    {
        return $this->run(function () use ($url, $options) {
            return $this->getAsyncManager()->await($this->getAsyncManager()->fetch($url, $options));
        });
    }

    public function asyncSleep(float $seconds): void
    {
        $this->run(function () use ($seconds) {
            $this->getAsyncManager()->await($this->getAsyncManager()->delay($seconds));
        });
    }

    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return $this->run(function () use ($asyncOperation, $timeout) {
            $promise = is_callable($asyncOperation)
                ? $this->getAsyncManager()->async($asyncOperation)()
                : $asyncOperation;

            $timeoutPromise = $this->getAsyncManager()->async(function () use ($timeout) {
                $this->getAsyncManager()->await($this->getAsyncManager()->delay($timeout));
                throw new Exception("Operation timed out after {$timeout} seconds");
            })();

            return $this->getAsyncManager()->await($this->getAsyncManager()->race([$promise, $timeoutPromise]));
        });
    }

    public function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        $start = microtime(true);
        $result = $this->run($asyncOperation);
        $duration = microtime(true) - $start;

        return [
            'result' => $result,
            'duration' => $duration,
            'duration_ms' => round($duration * 1000, 2),
        ];
    }
}
