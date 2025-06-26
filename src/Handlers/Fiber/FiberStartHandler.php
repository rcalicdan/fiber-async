<?php

namespace Rcalicdan\FiberAsync\Handlers\Fiber;

class FiberStartHandler
{
    public function startFiber(\Fiber $fiber): bool
    {
        if ($fiber->isTerminated() || $fiber->isStarted()) {
            return false;
        }

        try {
            $fiber->start();
            return true;
        } catch (\Throwable $e) {
            error_log('Fiber start error: ' . $e->getMessage());
            return false;
        }
    }

    public function canStart(\Fiber $fiber): bool
    {
        return !$fiber->isTerminated() && !$fiber->isStarted();
    }
}