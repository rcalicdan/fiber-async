<?php

use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\Api\Timer;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

if (! function_exists('in_fiber')) {
    /**
     * Check if the current execution context is within a PHP Fiber.
     *
     * This is essential for determining if async operations can be performed
     * safely or if they need to be wrapped in a fiber context first.
     *
     * @return bool True if executing within a fiber, false otherwise
     */
    function in_fiber(): bool
    {
        return Async::inFiber();
    }
}

if (! function_exists('async')) {
    /**
     * Convert a regular function into an async function that returns a Promise.
     *
     * The returned function will execute the original function within a fiber
     * context, enabling it to use async operations like await. This is the
     * primary method for creating async functions from synchronous code.
     *
     * @param  callable  $asyncFunction  The function to convert to async
     * @return callable An async version that returns a Promise
     *
     * @example
     * $asyncFunc = async(function($data) {
     *     $result = await(http_get('https://api.example.com'));
     *     return $result;
     * });
     */
    function async(callable $asyncFunction): callable
    {
        return Async::async($asyncFunction);
    }
}

if (! function_exists('await')) {
    /**
     * Suspend the current fiber until the promise resolves or rejects.
     *
     * This function pauses execution of the current fiber and returns control
     * to the event loop until the promise settles. Must be called from within
     * a fiber context. Returns the resolved value or throws on rejection.
     *
     * @param  PromiseInterface  $promise  The promise to await
     * @return mixed The resolved value of the promise
     *
     * @throws Exception If the promise is rejected
     *
     * @example
     * $result = await(http_get('https://api.example.com'));
     */
    function await(PromiseInterface $promise): mixed
    {
        return Async::await($promise);
    }
}

if (! function_exists('delay')) {
    /**
     * Create a promise that resolves after a specified time delay.
     *
     * This creates a timer-based promise that will resolve with null after
     * the specified delay. Useful for creating pauses in async execution
     * without blocking the event loop.
     *
     * @param  float  $seconds  Number of seconds to delay
     * @return PromiseInterface A promise that resolves after the delay
     *
     * @example
     * await(delay(2.5)); // Wait 2.5 seconds
     */
    function delay(float $seconds): PromiseInterface
    {
        return Timer::delay($seconds);
    }
}

if (! function_exists('all')) {
    /**
     * Wait for all promises to resolve and return their results in order.
     *
     * Creates a promise that resolves when all input promises resolve, with
     * an array of their results in the same order. If any promise rejects,
     * the returned promise immediately rejects with the first rejection reason.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with an array of all results
     *
     * @example
     * $results = await(all([
     *     http_get('https://api1.example.com'),
     *     http_get('https://api2.example.com')
     * ]));
     */
    function all(array $promises): PromiseInterface
    {
        return Promise::all($promises);
    }
}

if (! function_exists('race')) {
    /**
     * Return the first promise to settle (resolve or reject).
     *
     * Creates a promise that settles with the same value/reason as the first
     * promise in the array to settle. Useful for timeout scenarios or when
     * you need the fastest response from multiple sources.
     *
     * @param  array  $promises  Array of promises to race
     * @return PromiseInterface A promise that settles with the first result
     *
     * @example
     * $fastest = await(race([
     *     http_get('https://api1.example.com'),
     *     http_get('https://api2.example.com')
     * ]));
     */
    function race(array $promises): PromiseInterface
    {
        return Promise::race($promises);
    }
}

if (! function_exists('any')) {
    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with the first settled value
     *
     * @example
     * $promises = [
     *     http_get('https://api1.example.com'),
     *     http_get('https://api2.example.com'),
     *     http_get('https://api3.example.com')
     * ];
     * $result = await(any($promises)); // Resolves with the first settled value
     */
    function any(array $promises): PromiseInterface
    {
        return Promise::any($promises);
    }
}

if (! function_exists('timeout')) {
    /**
     * Run an async operation with a timeout limit.
     *
     * Executes the provided promises and ensures they complete within the
     * operation and automatically throws execption if the timout timer won.
     *
     * @param  float  $seconds  Number of seconds to wait before resolving
     * @return PromiseInterface A promise that resolves after the delay
     */
    function timeout(callable|PromiseInterface|array $promises, float $seconds): PromiseInterface
    {
        return Promise::timeout($promises, $seconds);
    }
}

if (! function_exists('resolve')) {
    /**
     * Create a promise that is already resolved with the given value.
     *
     * This is useful for creating resolved promises in async workflows or
     * for converting synchronous values into promise-compatible form.
     *
     * @param  mixed  $value  The value to resolve the promise with
     * @return PromiseInterface A promise resolved with the provided value
     *
     * @example
     * $promise = resolve('Hello World');
     * $result = await($promise); // 'Hello World'
     */
    function resolve(mixed $value): PromiseInterface
    {
        return Promise::resolve($value);
    }
}

if (! function_exists('reject')) {
    /**
     * Create a promise that is already rejected with the given reason.
     *
     * This is useful for creating rejected promises in async workflows or
     * for converting exceptions into promise-compatible form.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface A promise rejected with the provided reason
     *
     * @example
     * $promise = reject(new Exception('Something went wrong'));
     */
    function reject(mixed $reason): PromiseInterface
    {
        return Promise::reject($reason);
    }
}

if (! function_exists('try_async')) {
    /**
     * Create a safe async function with automatic error handling.
     *
     * The returned function will catch any exceptions thrown during execution
     * and convert them to rejected promises, preventing uncaught exceptions
     * from crashing the event loop.
     *
     * @param  callable  $asyncFunction  The async function to make safe
     * @return callable A safe version that always returns a promise
     *
     * @example
     * $safeFunc = try_async(function() {
     *     throw new Exception('This will be caught');
     * });
     */
    function try_async(callable $asyncFunction): callable
    {
        return Async::tryAsync($asyncFunction);
    }
}

if (! function_exists('asyncify')) {
    /**
     * Convert a synchronous function to work in async contexts.
     *
     * Wraps a synchronous function so it can be used alongside async operations
     * without blocking the event loop. The function will be executed in a way
     * that doesn't interfere with concurrent async operations.
     *
     * @param  callable  $syncFunction  The synchronous function to wrap
     * @return callable An async-compatible version of the function
     *
     * @example
     * $asyncFileRead = asyncify('file_get_contents');
     * $content = await($asyncFileRead('file.txt'));
     */
    function asyncify(callable $syncFunction): callable
    {
        return Async::asyncify($syncFunction);
    }
}

if (! function_exists('concurrent')) {
    /**
     * Execute multiple tasks concurrently with a concurrency limit.
     *
     * Processes an array of tasks (callables or promises) in batches to avoid
     * overwhelming the system. This is essential for handling large numbers
     * of concurrent operations without exhausting system resources.
     *
     * @param  array  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface A promise that resolves with all task results
     *
     * @example
     * $tasks = array_map(fn($url) => fn() => http_get($url), $urls);
     * $results = await(concurrent($tasks, 5));
     */
    function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return Promise::concurrent($tasks, $concurrency);
    }
}

if (! function_exists('batch')) {
    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for controlled
     * concurrency and resource management. It is particularly useful for
     * processing large datasets or performing operations that require
     * significant resources without overwhelming the system.
     *
     * @param  array  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface A promise that resolves with all results
     *
     * @example
     * $tasks = array_map(fn($url) => fn() => http_get($url), $urls);
     * $results = await(batch($tasks, 100, 5));
     */
    function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return Promise::batch($tasks, $batchSize, $concurrency);
    }
}
