<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Timer;

use Rcalicdan\FiberAsync\EventLoop\ValueObjects\Timer;

/**
 * Handles timer execution and readiness detection.
 *
 * This class manages the execution of timers that are ready to run,
 * filtering timers by readiness, and executing their callbacks.
 */
final readonly class TimerExecutionHandler
{
    /**
     * Execute all timers that are ready to run.
     *
     * Checks all timers in the array, executes those that are ready,
     * and removes them from the array after execution.
     *
     * @param  Timer[]  &$timers  Reference to the timers array
     * @param  float  $currentTime  Current timestamp for readiness check
     * @return bool True if any timers were processed
     */
    public function executeReadyTimers(array &$timers, float $currentTime): bool
    {
        $processed = false;

        foreach ($timers as $id => $timer) {
            if ($timer->isReady($currentTime)) {
                $timer->execute();
                unset($timers[$id]);
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * Get all timers that are ready to execute.
     *
     * Filters the timers array to return only those that are
     * ready to run at the current time.
     *
     * @param  Timer[]  $timers  Array of timers to check
     * @param  float  $currentTime  Current timestamp for readiness check
     * @return Timer[] Array of ready timers
     */
    public function getReadyTimers(array $timers, float $currentTime): array
    {
        return array_filter($timers, fn (Timer $timer) => $timer->isReady($currentTime));
    }

    /**
     * Execute a specific timer's callback.
     *
     * Safely executes the callback associated with the given timer.
     *
     * @param  Timer  $timer  The timer to execute
     */
    public function executeTimer(Timer $timer): void
    {
        $timer->execute();
    }
}
