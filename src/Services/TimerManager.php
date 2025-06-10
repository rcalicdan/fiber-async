<?php

namespace TrueAsync\Services;

use TrueAsync\ValueObjects\Timer;

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

    public function processTimers(): void
    {
        $currentTime = microtime(true);
        
        foreach ($this->timers as $id => $timer) {
            if ($timer->isReady($currentTime)) {
                $timer->execute();
                unset($this->timers[$id]);
            }
        }
    }

    public function hasTimers(): bool
    {
        return !empty($this->timers);
    }
}