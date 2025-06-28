<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Async;

/**
 * Check if code is running inside a Fiber
 */
function in_fiber(): bool
{
    return Async::inFiber();
}

/**
 * Creates an async function that returns a Promise
 */
function async(callable $asyncFunction): callable
{
    return Async::async($asyncFunction);
}

/**
 * Awaits a Promise and returns its resolved value or throws on rejection
 */
function await(PromiseInterface $promise): mixed
{
    return Async::await($promise);
}

/**
 * Delays execution for a specified number of seconds
 */
function delay(float $seconds): PromiseInterface
{
    return Async::delay($seconds);
}

/**
 * Makes an async HTTP request
 */
function fetch(string $url, array $options = []): PromiseInterface
{
    return Async::fetch($url, $options);
}

/**
 * Resolves when all promises are resolved, rejects if any promise rejects
 */
function all(array $promises): PromiseInterface
{
    return Async::all($promises);
}

/**
 * Resolves when any promise resolves, rejects only if all promises reject
 */
function race(array $promises): PromiseInterface
{
    return Async::race($promises);
}

/**
 * Creates a resolved promise
 */
function resolve(mixed $value): PromiseInterface
{
    return Async::resolve($value);
}

/**
 * Creates a rejected promise
 */
function reject(mixed $reason): PromiseInterface
{
    return Async::reject($reason);
}

/**
 * Try-catch wrapper for async operations
 */
function try_async(callable $asyncFunction): callable
{
    return Async::tryAsync($asyncFunction);
}

/**
 * Make any synchronous function asynchronous
 */
function asyncify(callable $syncFunction): callable
{
    return Async::asyncify($syncFunction);
}

/**
 * Guzzle HTTP client bridge
 */
function async_guzzle(string $method, string $url, array $options = []): PromiseInterface
{
    return Async::guzzle($method, $url, $options);
}

/**
 * Laravel HTTP client bridge
 */
function async_http()
{
    return Async::http();
}

/**
 * Wrap synchronous operations
 */
function wrap_sync(callable $syncCall): PromiseInterface
{
    return Async::wrapSync($syncCall);
}

/**
 * Run multiple async operations concurrently with concurrency limit
 */
function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
{
    return Async::concurrent($tasks, $concurrency);
}
