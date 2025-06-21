<?php

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Run async operations with automatic event loop management
 */
function run(callable|PromiseInterface $asyncOperation): mixed
{
    return Async::run($asyncOperation);
}

/**
 * Run multiple async operations concurrently
 */
function runAll(array $asyncOperations): array
{
    return Async::runAll($asyncOperations);
}

/**
 * Run async operations with concurrency limit
 */
function runConcurrent(array $asyncOperations, int $concurrency = 10): array
{
    return Async::runConcurrent($asyncOperations, $concurrency);
}

/**
 * Create and run a simple async task
 */
function task(callable $asyncFunction): mixed
{
    return Async::task($asyncFunction);
}

/**
 * Quick HTTP fetch with automatic loop management
 */
function quickFetch(string $url, array $options = []): array
{
    return Async::quickFetch($url, $options);
}

/**
 * Async delay with automatic loop management
 */
function asyncSleep(float $seconds): void
{
    Async::asyncSleep($seconds);
}

/**
 * Run with timeout
 */
function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
{
    return Async::runWithTimeout($asyncOperation, $timeout);
}

/**
 * Run and return both result and execution time
 */
function benchmark(callable|PromiseInterface $asyncOperation): array
{
    return Async::benchmark($asyncOperation);
}