<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

class ActivityHandler
{
    private int $lastActivity = 0;

    public function __construct()
    {
        $this->lastActivity = time();
    }

    public function updateLastActivity(): void
    {
        $this->lastActivity = time();
    }

    public function isIdle(): bool
    {
        return (time() - $this->lastActivity) > 5;
    }

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }
}