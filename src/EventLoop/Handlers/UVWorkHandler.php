<?php

namespace Rcalicdan\FiberAsync\EventLoop\Handlers;

use Rcalicdan\FiberAsync\EventLoop\Handlers\TickHandler;
use Rcalicdan\FiberAsync\EventLoop\Managers\FiberManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\FileManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\HttpRequestManager;

/**
 * UV-aware work handler that integrates with libuv event loop
 */
final class UvWorkHandler extends WorkHandler
{
    private $uvLoop;
    
    // UV run mode constants (hardcoded since they're not defined)
    private const UV_RUN_DEFAULT = 0;  // Run until no more active handles
    private const UV_RUN_ONCE = 1;     // Run until at least one event is processed
    private const UV_RUN_NOWAIT = 2;   // Process available events without blocking

    public function __construct(
        $uvLoop,
        $timerManager,
        HttpRequestManager $httpRequestManager,
        $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler,
        FileManager $fileManager,
        $socketManager,
    ) {
        $this->uvLoop = $uvLoop;
        
        parent::__construct(
            $timerManager,
            $httpRequestManager,
            $streamManager,
            $fiberManager,
            $tickHandler,
            $fileManager,
            $socketManager
        );
    }

    public function processWork(): bool
    {
        $workDone = false;

        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        // Use uv_run with mode 2 (UV_RUN_NOWAIT) for non-blocking execution
        if ($this->runUvLoop()) {
            $workDone = true;
        }

        if ($this->timerManager->processTimers()) {
            $workDone = true;
        }

        if ($this->socketManager->processSockets()) {
            $workDone = true;
        }

        if ($this->streamManager->hasWatchers()) {
            $this->streamManager->processStreams();
            $workDone = true;
        }

        if ($this->fiberManager->processFibers()) {
            $workDone = true;
        }

        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        if ($this->fileManager->processFileOperations()) {
            $workDone = true;
        }

        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }

    private function runUvLoop(): bool
    {
        try {
            $result = \uv_run($this->uvLoop, self::UV_RUN_NOWAIT);
            return $result > 0;
        } catch (\Error | \Exception $e) {
            error_log("UV loop error: " . $e->getMessage());
            return false;
        }
    }
}