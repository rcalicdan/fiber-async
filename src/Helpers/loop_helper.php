<?php

use Rcalicdan\FiberAsync\Api\AsyncLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

if (! function_exists('run')) {
    /**
     * Run an async operation with automatic event loop management.
     *
     * This function handles the complete lifecycle: starts the event loop,
     * executes the operation, waits for completion, and stops the loop.
     * This is the primary method for running async operations with minimal setup.
     *
     * @param  callable|PromiseInterface  $asyncOperation  The operation to execute
     * @return mixed The result of the async operation
     */
    function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return AsyncLoop::run($asyncOperation);
    }
}

if (! function_exists('run_all')) {
    /**
     * Run multiple async operations concurrently with automatic loop management.
     *
     * Starts all operations simultaneously, waits for all to complete, then
     * returns their results in the same order as the input array. The event
     * loop is managed automatically throughout the entire process.
     *
     * @param  array  $asyncOperations  Array of callables or promises to execute
     * @return array Results of all operations in the same order as input
     */
    function run_all(array $asyncOperations): array
    {
        return AsyncLoop::runAll($asyncOperations);
    }
}

if (! function_exists('run_concurrent')) {
    /**
     * Run async operations with concurrency control and automatic loop management.
     *
     * Executes operations in controlled batches to prevent system overload while
     * maintaining high throughput. The event loop lifecycle is handled automatically,
     * making this ideal for processing large numbers of operations safely.
     *
     * @param  array  $asyncOperations  Array of operations to execute
     * @param  int  $concurrency  Maximum number of concurrent operations
     * @return array Results of all operations
     */
    function run_concurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return AsyncLoop::runConcurrent($asyncOperations, $concurrency);
    }
}

if (! function_exists('task')) {
    /**
     * Create and run a simple async task with automatic event loop management.
     *
     * This is a convenience function for running a single async function without
     * manually managing the event loop. Perfect for simple async operations
     * that don't require complex setup.
     *
     * @param  callable  $asyncFunction  The async function to execute
     * @return mixed The result of the async function
     */
    function task(callable $asyncFunction): mixed
    {
        return AsyncLoop::task($asyncFunction);
    }
}

if (! function_exists('async_sleep')) {
    /**
     * Perform an async delay with automatic event loop management.
     *
     * Creates a delay without blocking the current thread, with the event loop
     * managed automatically. This is useful for simple timing operations that
     * don't require manual loop control.
     *
     * @param  float  $seconds  Number of seconds to delay
     */
    function async_sleep(float $seconds): void
    {
        AsyncLoop::asyncSleep($seconds);
    }
}

if (! function_exists('run_with_timeout')) {
    /**
     * Run an async operations with a timeout constraint and automatic loop management.
     *
     * Executes the operation with a maximum time limit. If the operation doesn't
     * complete within the timeout, it's cancelled and a timeout exception is thrown.
     * The event loop is managed automatically throughout.
     *
     * @param  callable|PromiseInterface|array  $asyncOperation  The operation to execute
     * @param  float  $timeout  Maximum time to wait in seconds
     * @return mixed The result of the operation if completed within timeout
     *
     * @throws Exception If the operation times out
     */
    function run_with_timeout(callable|PromiseInterface|array $asyncOperation, float $timeout): mixed
    {
        return AsyncLoop::runWithTimeout($asyncOperation, $timeout);
    }
}

if (! function_exists('run_batch')) {
    /**
     * Run async operations in batches with concurrency control and automatic loop management.
     *
     * @param  array  $asyncOperations  Array of operations to execute
     * @param  int  $batch  Number of operations to run in each batch
     * @param  int|null  $concurrency  Maximum number of concurrent operations per batch
     * @return array Results of all operations
     */
    function run_batch(array $asyncOperations, int $batch, ?int $concurrency = null): array
    {
        return AsyncLoop::runBatch($asyncOperations, $batch, $concurrency);
    }
}

if (! function_exists('benchmark')) {
    /**
     * Run an async operation and measure its performance metrics.
     *
     * Executes the operation while collecting timing and performance data.
     * Returns both the operation result and detailed benchmark information
     * including execution time, memory usage, and other performance metrics.
     *
     * @param  callable|PromiseInterface  $asyncOperation  The operation to benchmark
     * @return array Array containing 'result' and 'benchmark' keys with performance data
     */
    function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        return AsyncLoop::benchmark($asyncOperation);
    }
}
