<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\TimerManager;
use Rcalicdan\FiberAsync\Managers\FiberManager;

class SleepHandler
{
    private TimerManager $timerManager;
    private FiberManager $fiberManager;

    public function __construct(TimerManager $timerManager, FiberManager $fiberManager)
    {
        $this->timerManager = $timerManager;
        $this->fiberManager = $fiberManager;
    }

    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return !$hasImmediateWork && !$this->fiberManager->hasActiveFibers();
    }

    public function calculateOptimalSleep(): int
    {
        $nextTimer = $this->timerManager->getNextTimerDelay();

        if ($nextTimer !== null) {
            return min(1000, (int) ($nextTimer * 1000000));
        }

        return 100;
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}