<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;

final readonly class WorkHandler
{
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private TickHandler $tickHandler;

    public function __construct(
        TimerManager $timerManager,
        HttpRequestManager $httpRequestManager,
        StreamManager $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler
    ) {
        $this->timerManager = $timerManager;
        $this->httpRequestManager = $httpRequestManager;
        $this->streamManager = $streamManager;
        $this->fiberManager = $fiberManager;
        $this->tickHandler = $tickHandler;
    }

    public function hasWork(): bool
    {
        return $this->timerManager->hasTimers() ||
            $this->httpRequestManager->hasRequests() ||
            $this->streamManager->hasWatchers() ||
            $this->fiberManager->hasFibers() ||
            $this->tickHandler->hasTickCallbacks() ||
            $this->tickHandler->hasDeferredCallbacks();
    }

    public function processWork(): bool
    {
        $workDone = false;

        // Process immediate callbacks first
        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        // Process HTTP requests BEFORE fibers (allows requests to start immediately)
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        // Process fibers (they may add more HTTP requests)
        if ($this->fiberManager->processFibers()) {
            $workDone = true;
        }

        // Process HTTP requests again (handle newly added requests)
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        // Process timers
        if ($this->timerManager->processTimers()) {
            $workDone = true;
        }

        // Process streams
        if ($this->streamManager->processStreams()) {
            $workDone = true;
        }

        // Process deferred callbacks
        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }
}
