<?php

namespace Rcalicdan\FiberAsync\Loop\Handlers;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Handles concurrent execution of multiple async operations.
 *
 * This class provides methods for running multiple async operations
 * either all at once or with controlled concurrency limits.
 */
final readonly class ConcurrentExecutionHandler
{
    /**
     * Async operations instance for creating promises.
     */
    private AsyncOperations $asyncOps;

    /**
     * Loop execution handler for running operations.
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Initialize the concurrent execution handler.
     *
     * @param  AsyncOperations  $asyncOps  Async operations instance
     * @param  LoopExecutionHandler  $executionHandler  Loop execution handler
     */
    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    /**
     * Run all async operations concurrently without limits.
     *
     * Executes all provided operations simultaneously and waits for
     * all of them to complete before returning the results.
     *
     * @param  array<int|string, callable|PromiseInterface<mixed>>  $asyncOperations  Array of async operations to execute
     * @return array<int|string, mixed> Results from all operations in the same order
     */
    public function runAll(array $asyncOperations): array
    {
        /** @var array<int|string, mixed> */
        return $this->executionHandler->run(function () use ($asyncOperations) {
            /** @var array<int|string, PromiseInterface<mixed>> */
            $promises = [];

            foreach ($asyncOperations as $key => $operation) {
                $promises[$key] = $this->executionHandler->createPromiseFromOperation($operation);
            }

            return $this->asyncOps->await($this->asyncOps->all($promises));
        });
    }

    /**
     * Run async operations with controlled concurrency.
     *
     * Executes the operations with a maximum number of concurrent
     * operations running at any given time.
     *
     * @param  array<int|string, callable|PromiseInterface<mixed>>  $asyncOperations  Array of async operations to execute
     * @param  int  $concurrency  Maximum number of concurrent operations (default: 10)
     * @return array<int|string, mixed> Results from all operations
     */
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        /** @var array<int|string, mixed> */
        return $this->executionHandler->run(function () use ($asyncOperations, $concurrency) {
            return $this->asyncOps->await($this->asyncOps->concurrent($asyncOperations, $concurrency));
        });
    }

    /**
     * Run async operations in batches.
     *
     * @param  array<int|string, callable|PromiseInterface<mixed>>  $asyncOperations  Array of async operations to execute
     * @param  int  $batch  Batch size
     * @param  int|null  $concurrency  Maximum concurrency within each batch
     * @return array<int|string, mixed> Results from all operations
     */
    public function runBatch(array $asyncOperations, int $batch, ?int $concurrency = null): array
    {
        /** @var array<int|string, mixed> */
        return $this->executionHandler->run(function () use ($asyncOperations, $batch, $concurrency) {
            return $this->asyncOps->await($this->asyncOps->batch($asyncOperations, $batch, $concurrency));
        });
    }
}