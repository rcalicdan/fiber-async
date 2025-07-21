<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\LoopOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Static facade for accessing asynchronous operations and event loop management.
 *
 * This facade provides a simplified interface to the complex async subsystem,
 * combining both AsyncOperations and LoopOperations functionality through static
 * methods. It manages singleton instances internally and provides convenient
 * access to fiber-based asynchronous programming capabilities.
 *
 * This facade handles automatic initialization of the underlying async infrastructure
 * and provides both low-level async operations and high-level loop management
 * through a unified static API.
 */
final class Async
{
    /**
     * @var AsyncOperations|null Cached instance of core async operations handler
     */
    private static ?AsyncOperations $asyncOps = null;

    /**
     * @var LoopOperations|null Cached instance of loop operations handler
     */
    private static ?LoopOperations $loopOps = null;

    /**
     * Get the singleton instance of AsyncOperations with lazy initialization.
     *
     * @return AsyncOperations The core async operations handler
     */
    protected static function getAsyncOperations(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations;
        }

        return self::$asyncOps;
    }

    /**
     * Get the singleton instance of LoopOperations with lazy initialization.
     *
     * @return LoopOperations The loop operations handler with automatic lifecycle management
     */
    protected static function getLoopOperations(): LoopOperations
    {
        if (self::$loopOps === null) {
            self::$loopOps = new LoopOperations(self::getAsyncOperations());
        }

        return self::$loopOps;
    }

    /**
     * Reset all cached instances to their initial state.
     *
     * This method clears all singleton instances, forcing fresh initialization
     * on next access. Primarily useful for testing scenarios where clean state
     * is required between test cases.
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
        self::$loopOps = null;
    }

    /**
     * Check if the current execution context is within a PHP Fiber.
     *
     * This is essential for determining if async operations can be performed
     * safely or if they need to be wrapped in a fiber context first.
     *
     * @return bool True if executing within a fiber, false otherwise
     */
    public static function inFiber(): bool
    {
        return self::getAsyncOperations()->inFiber();
    }

    /**
     * Convert a regular function into an async function that returns a Promise.
     *
     * The returned function will execute the original function within a fiber
     * context, enabling it to use async operations like await. This is the
     * primary method for creating async functions from synchronous code.
     *
     * @param  callable  $asyncFunction  The function to convert to async
     * @return callable An async version that returns a Promise
     */
    public static function async(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->async($asyncFunction);
    }

    /**
     * Suspend the current fiber until the promise resolves or rejects.
     *
     * This method pauses execution of the current fiber and returns control
     * to the event loop until the promise settles. Must be called from within
     * a fiber context. Returns the resolved value or throws on rejection.
     *
     * @param  PromiseInterface  $promise  The promise to await
     * @return mixed The resolved value of the promise
     *
     * @throws \Exception If the promise is rejected
     */
    public static function await(PromiseInterface $promise): mixed
    {
        return self::getAsyncOperations()->await($promise);
    }

    /**
     * Create a promise that resolves after a specified time delay.
     *
     * This creates a timer-based promise that will resolve with null after
     * the specified delay. Useful for creating pauses in async execution
     * without blocking the event loop.
     *
     * @param  float  $seconds  Number of seconds to delay
     * @return PromiseInterface A promise that resolves after the delay
     */
    public static function delay(float $seconds): PromiseInterface
    {
        return self::getAsyncOperations()->delay($seconds);
    }

    /**
     * Wait for all promises to resolve and return their results in order.
     *
     * Creates a promise that resolves when all input promises resolve, with
     * an array of their results in the same order. If any promise rejects,
     * the returned promise immediately rejects with the first rejection reason.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with an array of all results
     */
    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->all($promises);
    }

    /**
     * Return the first promise to settle (resolve or reject).
     *
     * Creates a promise that settles with the same value/reason as the first
     * promise in the array to settle. Useful for timeout scenarios or when
     * you need the fastest response from multiple sources.
     *
     * @param  array  $promises  Array of promises to race
     * @return PromiseInterface A promise that settles with the first result
     */
    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->race($promises);
    }

    /**
     * Create a promise that is already resolved with the given value.
     *
     * This is useful for creating resolved promises in async workflows or
     * for converting synchronous values into promise-compatible form.
     *
     * @param  mixed  $value  The value to resolve the promise with
     * @return PromiseInterface A promise resolved with the provided value
     */
    public static function resolve(mixed $value): PromiseInterface
    {
        return self::getAsyncOperations()->resolve($value);
    }

    /**
     * Create a promise that is already rejected with the given reason.
     *
     * This is useful for creating rejected promises in async workflows or
     * for converting exceptions into promise-compatible form.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface A promise rejected with the provided reason
     */
    public static function reject(mixed $reason): PromiseInterface
    {
        return self::getAsyncOperations()->reject($reason);
    }

    /**
     * Create a safe async function with automatic error handling.
     *
     * The returned function will catch any exceptions thrown during execution
     * and convert them to rejected promises, preventing uncaught exceptions
     * from crashing the event loop.
     *
     * @param  callable  $asyncFunction  The async function to make safe
     * @return callable A safe version that always returns a promise
     */
    public static function tryAsync(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->tryAsync($asyncFunction);
    }

    /**
     * Convert a synchronous function to work in async contexts.
     *
     * Wraps a synchronous function so it can be used alongside async operations
     * without blocking the event loop. The function will be executed in a way
     * that doesn't interfere with concurrent async operations.
     *
     * @param  callable  $syncFunction  The synchronous function to wrap
     * @return callable An async-compatible version of the function
     */
    public static function asyncify(callable $syncFunction): callable
    {
        return self::getAsyncOperations()->asyncify($syncFunction);
    }

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
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOperations()->concurrent($tasks, $concurrency);
    }
}
