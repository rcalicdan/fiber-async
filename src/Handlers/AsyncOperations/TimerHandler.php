<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles timer-based asynchronous operations.
 *
 * This handler provides methods for creating time-based delays and scheduling
 * operations to run after a specified amount of time. It integrates with the
 * event loop's timer management system.
 */
final readonly class TimerHandler
{
    /**
     * Create a Promise that resolves after the specified delay.
     *
     * This method creates a timer-based delay that can be awaited in async
     * functions. The Promise will resolve with null after the specified
     * number of seconds have elapsed.
     *
     * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
     * @return PromiseInterface Promise that resolves after the delay
     */
    public function delay(float $seconds): PromiseInterface
    {
        return new AsyncPromise(function ($resolve) use ($seconds) {
            AsyncEventLoop::getInstance()->addTimer($seconds, function () use ($resolve) {
                $resolve(null);
            });
        });
    }
}
