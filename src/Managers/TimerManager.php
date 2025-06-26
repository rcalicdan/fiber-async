<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\ValueObjects\Timer;

class TimerManager
{
    /** @var Timer[] */
    private array $timers = [];

    public function addTimer(float $delay, callable $callback): string
    {
        $timer = new Timer($delay, $callback);
        $this->timers[$timer->getId()] = $timer;

        return $timer->getId();
    }

    public function processTimers(): bool
    {
        if (empty($this->timers)) {
            return false;
        }

        $currentTime = microtime(true);
        $processed = false;

        foreach ($this->timers as $id => $timer) {
            if ($timer->isReady($currentTime)) {
                $timer->execute();
                unset($this->timers[$id]);
                $processed = true;
            }
        }

        return $processed;
    }

    public function hasTimers(): bool
    {
        return ! empty($this->timers);
    }

    public function getNextTimerDelay(): ?float
    {
        if (empty($this->timers)) {
            return null;
        }

        $currentTime = microtime(true);
        $nextExecuteTime = PHP_FLOAT_MAX;

        foreach ($this->timers as $timer) {
            $nextExecuteTime = min($nextExecuteTime, $timer->getExecuteAt());
        }

        $delay = $nextExecuteTime - $currentTime;

        return $delay > 0 ? $delay : 0;
    }
}
