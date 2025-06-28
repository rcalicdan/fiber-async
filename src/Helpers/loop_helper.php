<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Async;

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
    return Async::run($asyncOperation);
}

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
    return Async::runAll($asyncOperations);
}

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
    return Async::runConcurrent($asyncOperations, $concurrency);
}

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
    return Async::task($asyncFunction);
}

/**
 * Perform an HTTP fetch with automatic event loop management.
 *
 * Handles the complete HTTP request lifecycle including starting the event
 * loop, making the request, waiting for the response, and cleaning up.
 * Returns the raw response data directly.
 *
 * @param  string  $url  The URL to fetch
 * @param  array  $options  HTTP request options
 * @return array The HTTP response data
 */
function quick_fetch(string $url, array $options = []): array
{
    return Async::quickFetch($url, $options);
}

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
    Async::asyncSleep($seconds);
}

/**
 * Run an async operation with a timeout constraint and automatic loop management.
 *
 * Executes the operation with a maximum time limit. If the operation doesn't
 * complete within the timeout, it's cancelled and a timeout exception is thrown.
 * The event loop is managed automatically throughout.
 *
 * @param  callable|PromiseInterface  $asyncOperation  The operation to execute
 * @param  float  $timeout  Maximum time to wait in seconds
 * @return mixed The result of the operation if completed within timeout
 *
 * @throws Exception If the operation times out
 */
function run_with_timeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
{
    return Async::runWithTimeout($asyncOperation, $timeout);
}

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
    return Async::benchmark($asyncOperation);
}
