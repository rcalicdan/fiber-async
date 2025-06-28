<?php

namespace Rcalicdan\FiberAsync\Handlers\Timer;

use Rcalicdan\FiberAsync\ValueObjects\Timer;

final readonly class TimerExecutionHandler
{
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

    public function getReadyTimers(array $timers, float $currentTime): array
    {
        return array_filter($timers, fn(Timer $timer) => $timer->isReady($currentTime));
    }

    public function executeTimer(Timer $timer): void
    {
        $timer->execute();
    }
}
