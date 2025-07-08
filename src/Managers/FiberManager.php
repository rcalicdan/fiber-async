<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\Fiber\FiberResumeHandler;
use Rcalicdan\FiberAsync\Handlers\Fiber\FiberStartHandler;
use Rcalicdan\FiberAsync\Handlers\Fiber\FiberStateHandler;

class FiberManager
{
    /** @var \Fiber[] */
    private array $fibers = [];
    /** @var \Fiber[] */
    private array $suspendedFibers = [];

    private FiberStartHandler $startHandler;
    private FiberResumeHandler $resumeHandler;
    private FiberStateHandler $stateHandler;

    public function __construct()
    {
        $this->startHandler = new FiberStartHandler;
        $this->resumeHandler = new FiberResumeHandler;
        $this->stateHandler = new FiberStateHandler;
    }

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

        if (! empty($this->fibers)) {
            $processed = $this->processNewFibers() || $processed;
        } elseif (! empty($this->suspendedFibers)) {
            $processed = $this->processSuspendedFibers() || $processed;
        }

        return $processed;
    }

    private function processNewFibers(): bool
    {
        $fibersToStart = $this->fibers;
        $this->fibers = [];
        $processed = false;

        foreach ($fibersToStart as $fiber) {
            if ($this->startHandler->canStart($fiber)) {
                if ($this->startHandler->startFiber($fiber)) {
                    $processed = true;
                }

                if ($fiber->isSuspended()) {
                    $this->suspendedFibers[] = $fiber;
                }
            }
        }

        return $processed;
    }

    private function processSuspendedFibers(): bool
    {
        $fibersToResume = $this->suspendedFibers;
        $this->suspendedFibers = [];
        $processed = false;

        foreach ($fibersToResume as $fiber) {
            if ($this->resumeHandler->canResume($fiber)) {
                if ($this->resumeHandler->resumeFiber($fiber)) {
                    $processed = true;
                }

                if ($fiber->isSuspended()) {
                    $this->suspendedFibers[] = $fiber;
                }
            }
        }

        return $processed;
    }

     public function hasFibers(): bool
    {
        // --- THE FIX IS HERE ---
        // A suspended fiber is still "work" that the loop needs to tend to.
        // It's waiting for an external event (like I/O) to be resumed.
        // The loop must continue to run to check for these events.
        foreach($this->fibers as $fiber) {
            if (!$fiber->isTerminated()) return true;
        }
        foreach($this->suspendedFibers as $fiber) {
            if (!$fiber->isTerminated()) return true;
        }
        
        return false;
    }

    public function hasActiveFibers(): bool
    {
        return $this->stateHandler->hasActiveFibers($this->suspendedFibers) || ! empty($this->fibers);
    }
}
