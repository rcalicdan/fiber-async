<?php

namespace Rcalicdan\FiberAsync\Services;

class FiberManager
{
    /** @var \Fiber[] */
    private array $newFibers = [];
    /** @var \Fiber[] */
    private array $suspendedFibers = [];
    /** @var \Fiber[] */
    private array $readyFibers = [];

    public function addFiber(\Fiber $fiber): void
    {
        $this->newFibers[] = $fiber;
    }

    public function processFibers(): bool
    {
        $processed = false;

        // Start new fibers
        if ($this->startNewFibers()) {
            $processed = true;
        }

        // Resume ready fibers
        if ($this->resumeReadyFibers()) {
            $processed = true;
        }

        // Check if any suspended fibers are ready to resume
        if ($this->checkSuspendedFibers()) {
            $processed = true;
        }

        return $processed;
    }

    private function startNewFibers(): bool
    {
        if (empty($this->newFibers)) {
            return false;
        }

        $started = false;
        $fibers = $this->newFibers;
        $this->newFibers = [];

        foreach ($fibers as $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }

            try {
                if (!$fiber->isStarted()) {
                    $fiber->start();
                    $started = true;

                    if ($fiber->isSuspended()) {
                        $this->suspendedFibers[] = $fiber;
                    }
                }
            } catch (\Throwable $e) {
                error_log('Fiber start error: ' . $e->getMessage());
            }
        }

        return $started;
    }

    private function resumeReadyFibers(): bool
    {
        if (empty($this->readyFibers)) {
            return false;
        }

        $resumed = false;
        $fibers = $this->readyFibers;
        $this->readyFibers = [];

        foreach ($fibers as $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }

            if ($fiber->isSuspended()) {
                try {
                    $fiber->resume();
                    $resumed = true;

                    if ($fiber->isSuspended()) {
                        $this->suspendedFibers[] = $fiber;
                    }
                } catch (\Throwable $e) {
                    error_log('Fiber resume error: ' . $e->getMessage());
                }
            }
        }

        return $resumed;
    }

    private function checkSuspendedFibers(): bool
    {
        if (empty($this->suspendedFibers)) {
            return false;
        }

        // Move suspended fibers to ready queue
        // In a real implementation, you'd check if their awaited promises are resolved
        $readyCount = 0;
        $stillSuspended = [];

        foreach ($this->suspendedFibers as $fiber) {
            if ($fiber->isTerminated()) {
                continue;
            }

            if ($fiber->isSuspended()) {
                // For now, make all suspended fibers ready for next tick
                // In practice, you'd check promise states here
                $this->readyFibers[] = $fiber;
                $readyCount++;
            } else {
                $stillSuspended[] = $fiber;
            }
        }

        $this->suspendedFibers = $stillSuspended;
        return $readyCount > 0;
    }

    public function hasFibers(): bool
    {
        return !empty($this->newFibers) || 
               !empty($this->suspendedFibers) || 
               !empty($this->readyFibers);
    }

    public function hasActiveFibers(): bool
    {
        foreach ($this->suspendedFibers as $fiber) {
            if (!$fiber->isTerminated()) {
                return true;
            }
        }

        return !empty($this->newFibers) || !empty($this->readyFibers);
    }
}