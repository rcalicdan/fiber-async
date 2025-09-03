<?php

namespace Rcalicdan\FiberAsync\EventLoop\Handlers;

use Rcalicdan\FiberAsync\EventLoop\Managers\FiberManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\FileManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\SocketManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\StreamManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\TimerManager;

/**
 * Orchestrates all units of work in the event loop:
 * - Next-tick and deferred callbacks
 * - Timers and fibers
 * - HTTP requests, sockets, streams, and file operations
 */
class WorkHandler
{
    /**
     * @var TimerManager Manages scheduled timers.
     */
    protected TimerManager $timerManager;

    /**
     * @var HttpRequestManager Manages outgoing HTTP requests.
     */
    protected HttpRequestManager $httpRequestManager;

    /**
     * @var StreamManager Manages stream watchers and processing.
     */
    protected StreamManager $streamManager;

    /**
     * @var FiberManager Manages fiber scheduling and execution.
     */
    protected FiberManager $fiberManager;

    /**
     * @var TickHandler Manages next-tick and deferred callbacks.
     */
    protected TickHandler $tickHandler;

    /**
     * @var FileManager Manages file system operations.
     */
    protected FileManager $fileManager;

    /**
     * @var SocketManager Manages socket watchers and processing.
     */
    protected SocketManager $socketManager;

    /**
     * @param  TimerManager  $timerManager  Timer scheduling/processing.
     * @param  HttpRequestManager  $httpRequestManager  HTTP request scheduling/processing.
     * @param  StreamManager  $streamManager  Stream watching/processing.
     * @param  FiberManager  $fiberManager  Fiber scheduling/processing.
     * @param  TickHandler  $tickHandler  Next-tick and deferred callbacks.
     * @param  FileManager  $fileManager  File system operations.
     * @param  SocketManager  $socketManager  Socket watching/processing.
     */
    public function __construct(
        TimerManager $timerManager,
        HttpRequestManager $httpRequestManager,
        StreamManager $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler,
        FileManager $fileManager,
        SocketManager $socketManager,
    ) {
        $this->timerManager = $timerManager;
        $this->httpRequestManager = $httpRequestManager;
        $this->streamManager = $streamManager;
        $this->fiberManager = $fiberManager;
        $this->tickHandler = $tickHandler;
        $this->fileManager = $fileManager;
        $this->socketManager = $socketManager;
    }

    /**
     * Determine if there is any pending work in the loop.
     *
     * Checks callbacks, timers, HTTP requests, file I/O, streams, sockets, and fibers.
     *
     * @return bool True if any work units are pending.
     */
    public function hasWork(): bool
    {
        return $this->tickHandler->hasTickCallbacks()
            || $this->tickHandler->hasDeferredCallbacks()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->fileManager->hasWork()
            || $this->streamManager->hasWatchers()
            || $this->socketManager->hasWatchers()
            || $this->fiberManager->hasFibers();
    }

    /**
     * Process one full cycle of work:
     * 1. Next-tick callbacks
     * 2. Timers and fibers
     * 3. I/O operations (HTTP, sockets, streams, files)
     * 4. Deferred callbacks
     *
     * @return bool True if any work was performed.
     */
    public function processWork(): bool
    {
        $workDone = false;

        // 1) Next-tick callbacks
        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        // 2) Timers and fibers
        $timerWork = $this->timerManager->processTimers();
        $fiberWork = $this->fiberManager->processFibers();
        if ($timerWork || $fiberWork) {
            $workDone = true;
        }

        // 3) I/O operations
        if ($this->processIOOperations()) {
            $workDone = true;
        }

        // 4) Deferred callbacks
        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }

    /**
     * Process all types of I/O in a single batch:
     * - HTTP requests
     * - Sockets
     * - Streams (only if watchers exist)
     * - File operations
     *
     * @return bool True if any I/O work was performed.
     */
    protected function processIOOperations(): bool
    {
        $workDone = false;

        // HTTP requests
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        // Socket I/O
        if ($this->socketManager->processSockets()) {
            $workDone = true;
        }

        // Stream I/O: process only when watchers exist
        if ($this->streamManager->hasWatchers()) {
            $this->streamManager->processStreams();
            $workDone = true;
        }

        // File operations
        if ($this->fileManager->processFileOperations()) {
            $workDone = true;
        }

        return $workDone;
    }
}
