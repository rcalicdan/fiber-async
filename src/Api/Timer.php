<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Static facade for timer-based asynchronous operations.
 *
 * This facade provides convenient methods for creating time-based promises
 * and managing temporal aspects of asynchronous workflows. It focuses on
 * operations that involve time delays, timeouts, and scheduling without
 * blocking the event loop.
 *
 * For core async operations and fiber management, see the Async class.
 * For promise creation and collection utilities, see the Promise class.
 */
final class Timer
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
     * Create a promise that resolves after a specified time delay.
     *
     * This creates a timer-based promise that will resolve with null after
     * the specified delay. The delay is non-blocking and allows other async
     * operations to continue executing. This is useful for creating pauses
     * in async execution, implementing retry delays, or rate limiting operations.
     *
     * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
     * @return PromiseInterface A promise that resolves with null after the delay
     */
    public static function delay(float $seconds): PromiseInterface
    {
        return self::getAsyncOperations()->delay($seconds);
    }
}