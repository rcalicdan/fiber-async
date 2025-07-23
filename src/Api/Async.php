<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Loop\LoopOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Static facade for core asynchronous operations and fiber management.
 *
 * This facade provides a simplified interface to fiber-based asynchronous programming
 * capabilities, focusing on execution control, function transformation, and async
 * workflow management. It handles automatic initialization of the underlying async
 * infrastructure and manages singleton instances internally.
 *
 * For promise creation and collection utilities, see the Promise class.
 * For timer-based operations, see the Timer class.
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
     * Create a safe async function with automatic error handling.
     *
     * The returned function will catch any exceptions thrown during execution
     * and convert them to rejected promises, preventing uncaught exceptions
     * from crashing the event loop. This is essential for building robust
     * async applications that can gracefully handle errors.
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
     * that doesn't interfere with concurrent async operations, making it safe
     * to use within fiber-based async workflows.
     *
     * @param  callable  $syncFunction  The synchronous function to wrap
     * @return callable An async-compatible version of the function
     */
    public static function asyncify(callable $syncFunction): callable
    {
        return self::getAsyncOperations()->asyncify($syncFunction);
    }
}
