<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Rcalicdan\FiberAsync\EventLoop;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;

/**
 * Handles timer-based asynchronous operations.
 */
final readonly class TimerHandler
{
    /**
     * Create a Promise that resolves after the specified delay.
     *
     * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
     * @return CancellablePromise Promise that resolves after the delay and can be cancelled
     */
    public function delay(float $seconds): CancellablePromise
    {
        $promise = new CancellablePromise;

        $timerId = EventLoop::getInstance()->addTimer($seconds, function () use ($promise) {
            if (! $promise->isCancelled()) {
                $promise->resolve(null);
            }
        });

        $promise->setTimerId($timerId);

        return $promise;
    }
}
