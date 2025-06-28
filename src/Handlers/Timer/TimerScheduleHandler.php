<?php

namespace Rcalicdan\FiberAsync\Handlers\Timer;

use Rcalicdan\FiberAsync\ValueObjects\Timer;

final readonly class TimerScheduleHandler
{
    public function createTimer(float $delay, callable $callback): Timer
    {
        return new Timer($delay, $callback);
    }

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
