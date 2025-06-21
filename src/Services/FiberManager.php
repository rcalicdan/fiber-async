<?php

namespace Rcalicdan\FiberAsync\Services;

class FiberManager
{
    /** @var \Fiber[] */
    private array $fibers = [];
    /** @var \Fiber[] */
    private array $suspendedFibers = [];

    public function addFiber(\Fiber $fiber): void
    {
        $this->fibers[] = $fiber;
    }

    public function processFibers(): bool
    {
        if (empty($this->fibers) && empty($this->suspendedFibers)) {
            return false;
        }

        $processed = false;
        $fibersToResumeThisTick = $this->suspendedFibers;
        $this->suspendedFibers = [];

        $fibersToStartThisTick = $this->fibers;
        $this->fibers = [];

        foreach ($fibersToStartThisTick as $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }

            try {
                if (! $fiber->isStarted()) {
                    $fiber->start();
                    $processed = true;
                }
                if ($fiber->isSuspended()) {
                    $this->suspendedFibers[] = $fiber;
                }
            } catch (\Throwable $e) {
                error_log('Fiber error: '.$e->getMessage());
            }
        }

        foreach ($fibersToResumeThisTick as $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }

            if ($fiber->isSuspended()) {
                try {
                    $fiber->resume();
                    $processed = true;

                    if ($fiber->isSuspended()) {
                        $this->suspendedFibers[] = $fiber;
                    }
                } catch (\Throwable $e) {
                    error_log('Fiber resume error: '.$e->getMessage());
                }
            }
        }

        return $processed;
    }

    public function hasFibers(): bool
    {
        return ! empty($this->fibers) || ! empty($this->suspendedFibers);
    }

    public function hasActiveFibers(): bool
    {
        foreach ($this->suspendedFibers as $fiber) {
            if (! $fiber->isTerminated()) {
                return true;
            }
        }

        return ! empty($this->fibers);
    }
}
