<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;

final class SleepHandler
{
    private TimerManager $timerManager;
    private FiberManager $fiberManager;
    private static int $minSleepThreshold = 50; // microseconds
    private static int $maxSleepDuration = 500; // microseconds

    public function __construct(TimerManager $timerManager, FiberManager $fiberManager)
    {
        $this->timerManager = $timerManager;
        $this->fiberManager = $fiberManager;
    }

    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return ! $hasImmediateWork && ! $this->fiberManager->hasActiveFibers();
    }

    public function calculateOptimalSleep(): int
    {
        $nextTimer = $this->timerManager->getNextTimerDelay();

        if ($nextTimer !== null) {
            $sleepMicros = (int) ($nextTimer * 1000000);

            // Skip very short sleeps to avoid system call overhead
            if ($sleepMicros < self::$minSleepThreshold) {
                return 0;
            }

            return min(self::$maxSleepDuration, $sleepMicros);
        }

        return self::$minSleepThreshold;
    }

    public function sleep(int $microseconds): void
    {
        // Only sleep if duration is above threshold
        if ($microseconds >= self::$minSleepThreshold) {
            usleep($microseconds);
        }
    }
}
