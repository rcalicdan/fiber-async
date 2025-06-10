<?php

namespace TrueAsync\Services;

class FiberManager
{
    /** @var \Fiber[] */
    private array $fibers = [];

    public function addFiber(\Fiber $fiber): void
    {
        $this->fibers[] = $fiber;
    }

    public function processFibers(): void
    {
        foreach ($this->fibers as $key => $fiber) {
            if ($fiber->isTerminated()) {
                unset($this->fibers[$key]);
            } elseif ($fiber->isSuspended()) {
                try {
                    if (!$fiber->isStarted()) {
                        $fiber->start();
                    } else {
                        $fiber->resume();
                    }
                } catch (\Throwable $e) {
                    unset($this->fibers[$key]);
                    error_log("Fiber error: " . $e->getMessage());
                }
            }
        }
    }

    public function hasFibers(): bool
    {
        return !empty($this->fibers);
    }
}