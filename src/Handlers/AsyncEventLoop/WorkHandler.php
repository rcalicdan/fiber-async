<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
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

    public function hasWork(): bool
    {
        return $this->tickHandler->hasTickCallbacks() ||
            $this->tickHandler->hasDeferredCallbacks() ||
            $this->timerManager->hasTimers() ||
            $this->httpRequestManager->hasRequests() ||
            $this->fileManager->hasWork() ||
            $this->streamManager->hasWatchers() ||
            $this->socketManager->hasWatchers() ||
            $this->fiberManager->hasFibers();
    }

    public function processWork(): bool
    {
        $workDone = false;
        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }
        if ($this->socketManager->processSockets()) {
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
        if ($this->timerManager->processTimers()) {
            $workDone = true;
        }
        if ($this->streamManager->processStreams()) {
            $workDone = true;
        }
        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }
}
