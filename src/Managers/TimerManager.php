<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\Timer\TimerExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\Timer\TimerScheduleHandler;

class TimerManager
{
    /** @var \Rcalicdan\FiberAsync\ValueObjects\Timer[] */
    private array $timers = [];

    private TimerExecutionHandler $executionHandler;
    private TimerScheduleHandler $scheduleHandler;

    public function __construct()
    {
        $this->executionHandler = new TimerExecutionHandler;
        $this->scheduleHandler = new TimerScheduleHandler;
    }

    public function addTimer(float $delay, callable $callback): string
    {
        $timer = $this->scheduleHandler->createTimer($delay, $callback);
        $this->timers[$timer->getId()] = $timer;

        return $timer->getId();
    }

    /**
     * Cancel a timer by its ID
     */
    public function cancelTimer(string $timerId): bool
    {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * Check if a timer exists and is active
     */
    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    public function processTimers(): bool
    {
        $currentTime = microtime(true);

        return $this->executionHandler->executeReadyTimers($this->timers, $currentTime);
    }

    public function hasTimers(): bool
    {
        return ! empty($this->timers);
    }

    public function getNextTimerDelay(): ?float
    {
        $currentTime = microtime(true);

        return $this->scheduleHandler->calculateDelay($this->timers, $currentTime);
    }
}