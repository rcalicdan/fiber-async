<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Uv;

use Rcalicdan\FiberAsync\EventLoop\Handlers\SleepHandler;

/**
 * UV-aware sleep handler that leverages libuv's efficient polling
 */
final class UvSleepHandler extends SleepHandler
{
    private $uvLoop;

    public function __construct($timerManager, $fiberManager, $uvLoop)
    {
        parent::__construct($timerManager, $fiberManager);
        $this->uvLoop = $uvLoop;
    }

    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return false;
    }

    public function calculateOptimalSleep(): int
    {
        return 0;
    }

    public function sleep(int $microseconds): void
    {
        // UV loop handles timing, don't manually sleep
        // This prevents interference with UV's timing mechanisms
    }
}
