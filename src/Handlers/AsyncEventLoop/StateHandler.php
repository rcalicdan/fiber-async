<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler;

class StateHandler
{
    private bool $running = true;

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