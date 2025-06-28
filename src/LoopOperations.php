<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\BenchmarkHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\ConcurrentExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\HttpExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\LoopExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\TaskExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\TimeoutHandler;

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
class LoopOperations
{
    /**
     * @var AsyncOperations Core async operations handler
     */
    private AsyncOperations $asyncOps;

    /**
     * @var LoopExecutionHandler Manages event loop execution lifecycle
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * @var ConcurrentExecutionHandler Handles concurrent operation execution
     */
    private ConcurrentExecutionHandler $concurrentHandler;

    /**
     * @var TaskExecutionHandler Manages simple task execution
     */
    private TaskExecutionHandler $taskHandler;

    /**
     * @var HttpExecutionHandler Handles HTTP operation execution
     */
    private HttpExecutionHandler $httpHandler;

    /**
     * @var TimeoutHandler Manages operations with timeout constraints
     */
    private TimeoutHandler $timeoutHandler;

    /**
     * @var BenchmarkHandler Provides performance measurement capabilities
     */
    private BenchmarkHandler $benchmarkHandler;

    /**
     * Initialize loop operations with all required handlers.
     *
     * @param AsyncOperations|null $asyncOps Optional async operations instance
     */
    public function __construct(?AsyncOperations $asyncOps = null)
    {
        $this->asyncOps = $asyncOps ?? new AsyncOperations;
        $this->executionHandler = new LoopExecutionHandler($this->asyncOps);
        $this->concurrentHandler = new ConcurrentExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->taskHandler = new TaskExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->httpHandler = new HttpExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->timeoutHandler = new TimeoutHandler($this->asyncOps, $this->executionHandler);
        $this->benchmarkHandler = new BenchmarkHandler($this->executionHandler);
    }

    /**
     * Run an async operation with automatic event loop management.
     *
     * This method handles starting the event loop, executing the operation,
     * and stopping the loop when complete. It's the primary method for
     * running async operations with minimal setup.
     *
     * @param callable|PromiseInterface $asyncOperation The operation to execute
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
     * @param array $asyncOperations Array of callables or promises to execute
     * @return array Results of all operations in the same order as input
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
     * @param array $asyncOperations Array of operations to execute
     * @param int $concurrency Maximum number of concurrent operations
     * @return array Results of all operations
     */
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return $this->concurrentHandler->runConcurrent($asyncOperations, $concurrency);
    }

    /**
     * Create and run a simple async task with automatic loop management.
     *
     * This is a convenience method for running a single async function
     * without manually managing the event loop.
     *
     * @param callable $asyncFunction The async function to execute
     * @return mixed The result of the async function
     */
    public function task(callable $asyncFunction): mixed
    {
        return $this->taskHandler->task($asyncFunction);
    }

    /**
     * Perform an async delay with automatic loop management.
     *
     * This method starts the event loop, waits for the specified duration,
     * then stops the loop. Useful for simple delay operations.
     *
     * @param float $seconds Number of seconds to delay
     */
    public function asyncSleep(float $seconds): void
    {
        $this->taskHandler->asyncSleep($seconds);
    }

    /**
     * Perform a quick HTTP fetch with automatic loop management.
     *
     * This method handles the entire HTTP request lifecycle including
     * starting the event loop, making the request, and returning the result.
     *
     * @param string $url The URL to fetch
     * @param array $options HTTP request options
     * @return array The HTTP response data
     */
    public function quickFetch(string $url, array $options = []): array
    {
        return $this->httpHandler->quickFetch($url, $options);
    }

    /**
     * Run an async operation with a timeout constraint.
     *
     * If the operation doesn't complete within the specified timeout,
     * it will be cancelled and a timeout exception will be thrown.
     *
     * @param callable|PromiseInterface $asyncOperation The operation to execute
     * @param float $timeout Maximum time to wait in seconds
     * @return mixed The result of the operation if completed within timeout
     * @throws \Exception If the operation times out
     */
    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return $this->timeoutHandler->runWithTimeout($asyncOperation, $timeout);
    }

    /**
     * Run an async operation and measure its execution time.
     *
     * Returns both the operation result and performance metrics including
     * execution time, memory usage, and other benchmarking data.
     *
     * @param callable|PromiseInterface $asyncOperation The operation to benchmark
     * @return array Array containing 'result' and 'benchmark' keys with timing data
     */
    public function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        return $this->benchmarkHandler->benchmark($asyncOperation);
    }

    /**
     * Format benchmark results into a human-readable string.
     *
     * Takes the array returned by benchmark() and formats it into a
     * readable string with execution time and performance metrics.
     *
     * @param array $benchmarkResult The result array from benchmark()
     * @return string Formatted benchmark information
     */
    public function formatBenchmark(array $benchmarkResult): string
    {
        return $this->benchmarkHandler->formatBenchmarkResult($benchmarkResult);
    }
}
