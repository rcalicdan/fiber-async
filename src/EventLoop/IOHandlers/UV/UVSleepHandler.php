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
        return !$hasImmediateWork && 
               !$this->fiberManager->hasActiveFibers() &&
               !\uv_loop_alive($this->uvLoop);
    }

    public function calculateOptimalSleep(): int
    {
        return 0;
    }

    public function sleep(int $microseconds): void
    {
        if (\uv_loop_alive($this->uvLoop)) {
            \uv_run_once($this->uvLoop);
        } else {
            parent::sleep($microseconds);
        }
    }
}