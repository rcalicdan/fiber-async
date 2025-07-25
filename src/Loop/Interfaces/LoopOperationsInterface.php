<?php

namespace Rcalicdan\FiberAsync\Loop\Interfaces;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * High-level operations for running async tasks with the event loop.
 *
 * This interface provides convenient methods for executing async operations,
 * managing concurrency, and performing common async patterns like timeouts
 * and benchmarking.
 */
interface LoopOperationsInterface
{
    /**
     * Runs a single async operation and returns its result.
     *
     * @param  callable|PromiseInterface  $asyncOperation  The async operation to run
     * @return mixed The result of the async operation
     *
     * @throws mixed Any exception thrown by the async operation
     */
    public function run(callable|PromiseInterface $asyncOperation): mixed;

    /**
     * Runs multiple async operations sequentially and returns all results.
     *
     * @param  array  $asyncOperations  Array of callables or promises to execute
     * @return array Array of results in the same order as input operations
     */
    public function runAll(array $asyncOperations): array;

    /**
     * Runs multiple async operations with controlled concurrency.
     *
     * @param  array  $asyncOperations  Array of callables or promises to execute
     * @param  int  $concurrency  Maximum number of concurrent operations (default: 10)
     * @return array Array of results in the same order as input operations
     */
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array;

    /**
     * Executes an async function as a task and returns its result.
     *
     * Similar to run() but specifically designed for task execution patterns.
     *
     * @param  callable  $asyncFunction  The async function to execute as a task
     * @return mixed The result of the task execution
     */
    public function task(callable $asyncFunction): mixed;

    /**
     * Asynchronously sleeps for the specified duration.
     *
     * This method should be called within an async context and will
     * yield control back to the event loop during the sleep period.
     *
     * @param  float  $seconds  Duration to sleep in seconds (supports fractions)
     */
    public function asyncSleep(float $seconds): void;

    /**
     * Runs an async operation with a timeout.
     *
     * If the operation doesn't complete within the timeout period,
     * a timeout exception will be thrown.
     *
     * @param  callable|PromiseInterface  $asyncOperation  The operation to run
     * @param  float  $timeout  Timeout in seconds
     * @return mixed The result of the operation
     *
     * @throws \Exception If the operation times out
     */
    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed;

    /**
     * Benchmarks an async operation and returns performance metrics.
     *
     * @param  callable|PromiseInterface  $asyncOperation  The operation to benchmark
     * @return array Benchmark results including execution time, memory usage, etc.
     */
    public function benchmark(callable|PromiseInterface $asyncOperation): array;

    /**
     * Formats benchmark results into a human-readable string.
     *
     * @param  array  $benchmarkResult  The benchmark data from benchmark() method
     * @return string Formatted benchmark report
     */
    public function formatBenchmark(array $benchmarkResult): string;
}
