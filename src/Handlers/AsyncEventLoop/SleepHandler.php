<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\TimerManager;
use Rcalicdan\FiberAsync\Managers\FiberManager;

/**
 * Handles sleep logic and optimization for the event loop.
 * 
 * This handler determines when the event loop should sleep and calculates
 * optimal sleep durations based on pending work, active fibers, and upcoming
 * timer events. This helps reduce CPU usage when the loop is waiting for events.
 */
final readonly class SleepHandler
{
    private TimerManager $timerManager;
    private FiberManager $fiberManager;

    /**
     * @param TimerManager $timerManager Manager for handling timer operations
     * @param FiberManager $fiberManager Manager for handling fiber operations
     */
    public function __construct(TimerManager $timerManager, FiberManager $fiberManager)
    {
        $this->timerManager = $timerManager;
        $this->fiberManager = $fiberManager;
    }

    /**
     * Determine if the event loop should sleep based on available work.
     * 
     * The loop should sleep when there's no immediate work to process
     * and no active fibers that might produce work.
     * 
     * @param bool $hasImmediateWork Whether there's immediate work to process
     * @return bool True if the loop should sleep, false otherwise
     */
    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return !$hasImmediateWork && !$this->fiberManager->hasActiveFibers();
    }

    /**
     * Calculate the optimal sleep duration in microseconds.
     * 
     * This considers upcoming timer events to avoid oversleeping and missing
     * scheduled callbacks. Returns a maximum of 1000 microseconds (1ms) or
     * defaults to 100 microseconds if no timers are pending.
     * 
     * @return int Sleep duration in microseconds
     */
    public function calculateOptimalSleep(): int
    {
        $nextTimer = $this->timerManager->getNextTimerDelay();

        if ($nextTimer !== null) {
            return min(1000, (int) ($nextTimer * 1000000));
        }

        return 100;
    }

    /**
     * Sleep for the specified number of microseconds.
     * 
     * Only sleeps if the duration is positive to avoid unnecessary system calls.
     * 
     * @param int $microseconds Number of microseconds to sleep
     */
    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
