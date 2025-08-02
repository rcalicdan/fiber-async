<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Static api for promise creation and collection utilities.
 *
 * This facade provides convenient methods for creating promises and coordinating
 * multiple promises through collection patterns like all, race, and concurrent
 * execution. It focuses purely on promise manipulation and combination patterns
 * commonly used in asynchronous programming.
 *
 * For core async operations and fiber management, see the Async class.
 * For timer-based operations, see the Timer class.
 */
final class Promise
{
    /**
     * @var AsyncOperations|null Cached instance of core async operations handler
     */
    private static ?AsyncOperations $asyncOps = null;

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
     * Reset all cached instances to their initial state.
     *
     * This method clears all singleton instances, forcing fresh initialization
     * on next access. Primarily useful for testing scenarios where clean state
     * is required between test cases.
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
    }

    /**
     * Create a promise that is already resolved with the given value.
     *
     * This is useful for creating resolved promises in async workflows or
     * for converting synchronous values into promise-compatible form. The
     * promise will immediately resolve with the provided value when awaited.
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
     * for converting exceptions into promise-compatible form. The promise
     * will immediately reject with the provided reason when awaited.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface A promise rejected with the provided reason
     */
    public static function reject(mixed $reason): PromiseInterface
    {
        return self::getAsyncOperations()->reject($reason);
    }

    /**
     * Wait for all promises to resolve and return their results in order.
     *
     * Creates a promise that resolves when all input promises resolve, with
     * an array of their results in the same order as the input array. If any
     * promise rejects, the returned promise immediately rejects with the first
     * rejection reason, following fail-fast semantics.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with an array of all results
     */
    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->all($promises);
    }

    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with the first settled value
     */
    public static function any(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->any($promises);
    }

    /**
     * Return the first promise to settle (resolve or reject).
     *
     * Creates a promise that settles with the same value/reason as the first
     * promise in the array to settle. This is useful for timeout scenarios,
     * when you need the fastest response from multiple sources, or when
     * implementing fallback mechanisms.
     *
     * @param  array  $promises  Array of promises to race
     * @return PromiseInterface A promise that settles with the first result
     */
    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->race($promises);
    }

    /**
     * Create a promise that resolves after a specified delay.
     *
     * This is useful for implementing timeouts, delays, or scheduling
     * operations to execute after a certain period. The promise will
     * resolve with null after the specified number of seconds.
     * automatically throws execption if the timout timer won.
     *
     * @param  float  $seconds  Number of seconds to wait before resolving
     * @return PromiseInterface A promise that resolves after the delay
     */
    public static function timeout(callable|PromiseInterface|array $promises, float $seconds): PromiseInterface
    {
        return self::getAsyncOperations()->timeout($promises, $seconds);
    }

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit.
     *
     * IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * @param array $tasks Array of callables that return Promises, or Promise instances
     *                     Note: Promise instances will be awaited but cannot be truly
     *                     limited since they're already running
     * @param int $concurrency Maximum number of tasks to run simultaneously
     * @return PromiseInterface Promise that resolves with an array of all results
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOperations()->concurrent($tasks, $concurrency);
    }

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for controlled
     * concurrency and resource management. It is particularly useful for
     * processing large datasets or performing operations that require
     * significant resources without overwhelming the system.
     *
     * @param  array  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently (default: 10)
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10)
     * @return PromiseInterface A promise that resolves with all task results
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getAsyncOperations()->batch($tasks, $batchSize, $concurrency);
    }
}
