<?php

namespace Rcalicdan\FiberAsync\Handlers\Fiber;

final readonly class FiberResumeHandler
{
    public function resumeFiber(\Fiber $fiber): bool
    {
        if ($fiber->isTerminated() || !$fiber->isSuspended()) {
            return false;
        }

        try {
            $fiber->resume();
            return true;
        } catch (\Throwable $e) {
            error_log('Fiber resume error: ' . $e->getMessage());
            return false;
        }
    }

    public function canResume(\Fiber $fiber): bool
    {
        return !$fiber->isTerminated() && $fiber->isSuspended();
    }
}