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
    
    // UV run mode constants
    private const UV_RUN_DEFAULT = 0;
    private const UV_RUN_ONCE = 1;
    private const UV_RUN_NOWAIT = 2;

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

        // 1. Process next-tick callbacks first
        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        // 2. Let UV handle its events (including timers)
        if ($this->runUvLoop()) {
            $workDone = true;
        }

        // 3. Process non-UV components
        if ($this->fiberManager->processFibers()) {
            $workDone = true;
        }

        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        if ($this->fileManager->processFileOperations()) {
            $workDone = true;
        }

        // 4. Process deferred callbacks
        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }

    private function runUvLoop(): bool
    {
        try {
            // Use UV_RUN_NOWAIT to process available events without blocking
            $result = \uv_run($this->uvLoop, self::UV_RUN_NOWAIT);
            return $result > 0;
        } catch (\Error | \Exception $e) {
            error_log("UV loop error: " . $e->getMessage());
            return false;
        }
    }
}