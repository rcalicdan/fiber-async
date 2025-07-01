<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

final class StateHandler
{
    private bool $running = false;

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function start(): void
    {
        $this->running = true;
    }
}