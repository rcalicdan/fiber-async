<?php

namespace Rcalicdan\FiberAsync\EventLoop\Interfaces;

interface TimerHandlerInterface
{
    public function addTimer(float $delay, callable $callback): string;
    public function cancelTimer(string $timerId): bool;
    public function processTimers(): bool;
    public function hasTimers(): bool;
    public function clearAllTimers(): void;
    public function getNextTimerDelay(): ?float;
}

