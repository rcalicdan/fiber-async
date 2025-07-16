<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\PDOManager;
use Rcalicdan\FiberAsync\Managers\SocketManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;

final readonly class WorkHandler
{
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private TickHandler $tickHandler;
    private FileManager $fileManager;
    private SocketManager $socketManager;
    private PDOManager $pdoManager;

    public function __construct(
        TimerManager $timerManager,
        HttpRequestManager $httpRequestManager,
        StreamManager $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler,
        FileManager $fileManager,
        SocketManager $socketManager,
        PDOManager $pdoManager,
    ) {
        $this->timerManager = $timerManager;
        $this->httpRequestManager = $httpRequestManager;
        $this->streamManager = $streamManager;
        $this->fiberManager = $fiberManager;
        $this->tickHandler = $tickHandler;
        $this->fileManager = $fileManager;
        $this->socketManager = $socketManager;
        $this->pdoManager = $pdoManager;
    }

    public function hasWork(): bool
    {
        return $this->tickHandler->hasTickCallbacks() ||
            $this->tickHandler->hasDeferredCallbacks() ||
            $this->timerManager->hasTimers() ||
            $this->httpRequestManager->hasRequests() ||
            $this->fileManager->hasWork() ||
            $this->streamManager->hasWatchers() ||
            $this->socketManager->hasWatchers() ||
            $this->fiberManager->hasFibers() ||
            $this->pdoManager->hasPendingOperations();
    }

    public function processWork(): bool
    {
        $workDone = false;

        // Process high-priority work first
        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        // Batch process timers and fibers together for better cache locality
        $timerWork = $this->timerManager->processTimers();
        $fiberWork = $this->fiberManager->processFibers();

        if ($timerWork || $fiberWork) {
            $workDone = true;
        }

        // Process I/O operations
        if ($this->processIOOperations()) {
            $workDone = true;
        }

        // Process deferred callbacks last
        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }

    private function processIOOperations(): bool
    {
        $workDone = false;

        // Process all I/O in one batch to minimize system calls
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        if ($this->socketManager->processSockets()) {
            $workDone = true;
        }

        if ($this->streamManager->processStreams()) {
            $workDone = true;
        }

        if ($this->fileManager->processFileOperations()) {
            $workDone = true;
        }

        if ($this->pdoManager->processOperations()) {
            $workDone = true;
        }

        return $workDone;
    }
}
