<?php

namespace Rcalicdan\FiberAsync\Handlers\Fiber;

class FiberStateHandler
{
    public function filterActiveFibers(array $fibers): array
    {
        return array_filter($fibers, fn(\Fiber $fiber) => !$fiber->isTerminated());
    }

    public function filterSuspendedFibers(array $fibers): array
    {
        return array_filter($fibers, fn(\Fiber $fiber) => $fiber->isSuspended() && !$fiber->isTerminated());
    }

    public function hasActiveFibers(array $fibers): bool
    {
        foreach ($fibers as $fiber) {
            if (!$fiber->isTerminated()) {
                return true;
            }
        }
        return false;
    }

    public function getFiberState(\Fiber $fiber): string
    {
        if ($fiber->isTerminated()) {
            return 'terminated';
        }
        if ($fiber->isSuspended()) {
            return 'suspended';
        }
        if ($fiber->isStarted()) {
            return 'running';
        }
        return 'not_started';
    }
}
