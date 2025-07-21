<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Timer;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Timer;

/**
 * Handles timer scheduling and delay calculations.
 *
 * This class manages timer creation, scheduling calculations,
 * and determining optimal sleep durations based on pending timers.
 */
final readonly class TimerScheduleHandler
{
    /**
     * Create a new timer with the specified delay and callback.
     *
     * Creates a Timer object that will execute the callback
     * after the specified delay from the current time.
     *
     * @param  float  $delay  Delay in seconds before execution
     * @param  callable  $callback  Callback to execute when timer fires
     * @return Timer The created timer object
     */
    public function createTimer(float $delay, callable $callback): Timer
    {
        return new Timer($delay, $callback);
    }

    /**
     * Get the next execution time from an array of timers.
     *
     * Finds the earliest execution time among all pending timers.
     * Returns null if no timers are pending.
     *
     * @param  Timer[]  $timers  Array of timers to check
     * @return float|null The earliest execution time or null
     */
    public function getNextExecutionTime(array $timers): ?float
    {
        if (empty($timers)) {
            return null;
        }

        $nextExecuteTime = PHP_FLOAT_MAX;

        foreach ($timers as $timer) {
            $nextExecuteTime = min($nextExecuteTime, $timer->getExecuteAt());
        }

        return $nextExecuteTime;
    }

    /**
     * Calculate the optimal sleep duration based on pending timers.
     *
     * Determines the shortest time until the next timer's execution.
     * If no timers are pending, returns 0 to signal an immediate wakeup.
     *
     * @param  Timer[]  $timers  Array of timers to check
     * @param  float  $currentTime  Current timestamp for delay calculation
     * @return float|null Optimal sleep duration in seconds
     */
    public function calculateDelay(array $timers, float $currentTime): ?float
    {
        $nextExecuteTime = $this->getNextExecutionTime($timers);

        if ($nextExecuteTime === null) {
            return null;
        }

        $delay = $nextExecuteTime - $currentTime;

        return $delay > 0 ? $delay : 0;
    }
}
