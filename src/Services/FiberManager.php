<?php

namespace FiberAsync\Services;

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

        foreach ($this->fibers as $key => $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->fibers[$key]);
                continue;
            }

            try {
                if (!$fiber->isStarted()) {
                    $fiber->start();
                    $processed = true;
                    
                    if ($fiber->isSuspended()) {
                        $this->suspendedFibers[] = $fiber;
                    }
                } elseif ($fiber->isSuspended()) {
                    $this->suspendedFibers[] = $fiber;
                }
                
                unset($this->fibers[$key]);
            } catch (\Throwable $e) {
                unset($this->fibers[$key]);
                error_log("Fiber error: " . $e->getMessage());
            }
        }

        foreach ($this->suspendedFibers as $key => $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->suspendedFibers[$key]);
                continue;
            }

            if ($fiber->isSuspended()) {
                try {
                    $fiber->resume();
                    $processed = true;
                    
                    if ($fiber->isTerminated()) {
                        unset($this->suspendedFibers[$key]);
                    }
                } catch (\Throwable $e) {
                    unset($this->suspendedFibers[$key]);
                    error_log("Fiber resume error: " . $e->getMessage());
                }
            }
        }

        return $processed;
    }

    public function hasFibers(): bool
    {
        return !empty($this->fibers) || !empty($this->suspendedFibers);
    }

    public function hasActiveFibers(): bool
    {
        foreach ($this->suspendedFibers as $fiber) {
            if (!$fiber->isTerminated()) {
                return true;
            }
        }
        return !empty($this->fibers);
    }
}