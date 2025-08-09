<?php

namespace Rcalicdan\FiberAsync\Loop;

use Exception;
use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Loop\Handlers\ConcurrentExecutionHandler;
use Rcalicdan\FiberAsync\Loop\Handlers\LoopExecutionHandler;
use Rcalicdan\FiberAsync\Loop\Handlers\TimeoutHandler;
use Rcalicdan\FiberAsync\Loop\Interfaces\LoopOperationsInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * High-level operations that manage the event loop lifecycle automatically.
 *
 * This class provides convenient methods for running asynchronous operations
 * with automatic event loop management. It handles starting and stopping the
 * event loop, managing execution contexts, and providing utilities for common
 * async patterns like timeouts, benchmarking, and concurrent execution.
 *
 * Unlike AsyncOperations which requires manual loop management, this class
 * provides a "run and forget" interface for simpler async operation execution.
 */
class LoopOperations implements LoopOperationsInterface
{
    /**
     * Core async operations handler
     */
    private AsyncOperations $asyncOps;

    /**
     * Manages event loop execution lifecycle
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Handles concurrent operation execution
     */
    private ConcurrentExecutionHandler $concurrentHandler;

    /**
     * Manages operations with timeout constraints
     */
    private TimeoutHandler $timeoutHandler;

    /**
     * Initialize loop operations with all required handlers.
     *
     * @param  AsyncOperations|null  $asyncOps  Optional async operations instance
     */
    public function __construct(?AsyncOperations $asyncOps = null)
    {
        $this->asyncOps = $asyncOps ?? new AsyncOperations;
        $this->executionHandler = new LoopExecutionHandler($this->asyncOps);
        $this->concurrentHandler = new ConcurrentExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->timeoutHandler = new TimeoutHandler($this->asyncOps, $this->executionHandler);
    }

    /**
     * Run an async operation with automatic event loop management.
     *
     * This method handles starting the event loop, executing the operation,
     * and stopping the loop when complete. It's the primary method for
     * running async operations with minimal setup.
     *
     * @param  callable|PromiseInterface<mixed>  $asyncOperation  The operation to execute
     * @return mixed The result of the async operation
     */
    public function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return $this->executionHandler->run($asyncOperation);
    }

    /**
     * Run multiple async operations concurrently and wait for all to complete.
     *
     * All operations are started simultaneously and the method waits for
     * all to complete before returning their results in the same order.
     *
     * @param  array<int|string, callable|PromiseInterface<mixed>>  $asyncOperations  Array of callables or promises to execute
     * @return array<int|string, mixed> Results of all operations in the same order as input
     */
    public function runAll(array $asyncOperations): array
    {
        return $this->concurrentHandler->runAll($asyncOperations);
    }

    /**
     * Run async operations with a concurrency limit.
     *
     * Executes operations in batches to prevent overwhelming the system.
     * Useful for processing large numbers of operations with resource constraints.
     *
     * @param  array<int|string, callable|PromiseInterface<mixed>>  $asyncOperations  Array of operations to execute
     * @param  int  $concurrency  Maximum number of concurrent operations
     * @return array<int|string, mixed> Results of all operations
     */
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return $this->concurrentHandler->runConcurrent($asyncOperations, $concurrency);
    }

    /**
     * Run an async operation with a timeout constraint.
     *
     * If the operation doesn't complete within the specified timeout,
     * it will be cancelled and a timeout exception will be thrown.
     *
     * @param  callable|PromiseInterface<mixed>|array<int|string, callable|PromiseInterface<mixed>>  $asyncOperation  The operation to execute
     * @param  float  $timeout  Maximum time to wait in seconds
     * @return mixed The result of the operation if completed within timeout
     *
     * @throws Exception If the operation times out
     */
    public function runWithTimeout(callable|PromiseInterface|array $asyncOperation, float $timeout): mixed
    {
        return $this->timeoutHandler->runWithTimeout($asyncOperation, $timeout);
    }

    /**
     * Run async operations in batches with concurrency control and automatic loop management.
     *
     * @param  array<int|string, callable|PromiseInterface<mixed>>  $asyncOperations  Array of operations to execute
     * @param  int  $batch  Number of operations to run in each batch
     * @param  int|null  $concurrency  Maximum number of concurrent operations per batch
     * @return array<int|string, mixed> Results of all operations
     */
    public function runBatch(array $asyncOperations, int $batch, ?int $concurrency = null): array
    {
        return $this->concurrentHandler->runBatch($asyncOperations, $batch, $concurrency);
    }
}