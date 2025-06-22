<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Exception;
use Throwable;

class LoopOperations
{
    private AsyncOperations $asyncOps;

    public function __construct(?AsyncOperations $asyncOps = null)
    {
        $this->asyncOps = $asyncOps ?? new AsyncOperations();
    }

    /**
     * Run async operations with automatic event loop management
     */
    public function run(callable|PromiseInterface $asyncOperation): mixed
    {
        $loop = AsyncEventLoop::getInstance();
        $result = null;
        $error = null;
        $completed = false;

        $promise = is_callable($asyncOperation)
            ? $this->asyncOps->async($asyncOperation)()
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

        while (!$completed && !$loop->isIdle()) {
            $loop->run();
            if ($completed) {
                break;
            }
            usleep(1000); // 1ms
        }

        if ($error !== null) {
            throw $error instanceof Throwable ? $error : new Exception((string) $error);
        }

        return $result;
    }

    /**
     * Run multiple async operations concurrently
     */
    public function runAll(array $asyncOperations): array
    {
        return $this->run(function () use ($asyncOperations) {
            $promises = [];

            foreach ($asyncOperations as $key => $operation) {
                if (is_callable($operation)) {
                    $promises[$key] = $this->asyncOps->async($operation)();
                } else {
                    $promises[$key] = $operation;
                }
            }

            return $this->asyncOps->await($this->asyncOps->all($promises));
        });
    }

    /**
     * Run async operations with concurrency limit
     */
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return $this->run(function () use ($asyncOperations, $concurrency) {
            return $this->asyncOps->await($this->asyncOps->concurrent($asyncOperations, $concurrency));
        });
    }

    /**
     * Create and run a simple async task
     */
    public function task(callable $asyncFunction): mixed
    {
        return $this->run($this->asyncOps->async($asyncFunction)());
    }

    /**
     * Quick HTTP fetch with automatic loop management
     */
    public function quickFetch(string $url, array $options = []): array
    {
        return $this->run(function () use ($url, $options) {
            return $this->asyncOps->await($this->asyncOps->fetch($url, $options));
        });
    }

    /**
     * Async delay with automatic loop management
     */
    public function asyncSleep(float $seconds): void
    {
        $this->run(function () use ($seconds) {
            $this->asyncOps->await($this->asyncOps->delay($seconds));
        });
    }

    /**
     * Run with timeout
     */
    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return $this->run(function () use ($asyncOperation, $timeout) {
            $promise = is_callable($asyncOperation) ? $this->asyncOps->async($asyncOperation)() : $asyncOperation;

            $timeoutPromise = $this->asyncOps->async(function () use ($timeout) {
                $this->asyncOps->await($this->asyncOps->delay($timeout));
                throw new Exception("Operation timed out after {$timeout} seconds");
            })();

            return $this->asyncOps->await($this->asyncOps->race([$promise, $timeoutPromise]));
        });
    }

    /**
     * Run and return both result and execution time
     */
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
