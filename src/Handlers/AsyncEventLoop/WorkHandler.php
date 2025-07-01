<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
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
    private FileManager $fileManager;

    public function __construct(
        TimerManager $timerManager,
        HttpRequestManager $httpRequestManager,
        StreamManager $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler,
        FileManager $fileManager
    ) {
        $this->timerManager = $timerManager;
        $this->httpRequestManager = $httpRequestManager;
        $this->streamManager = $streamManager;
        $this->fiberManager = $fiberManager;
        $this->tickHandler = $tickHandler;
        $this->fileManager = $fileManager;
    }

    public function hasWork(): bool
    {
        return $this->tickHandler->hasTickCallbacks() ||
            $this->tickHandler->hasDeferredCallbacks() ||
            $this->timerManager->hasTimers() ||
            $this->httpRequestManager->hasRequests() ||
            $this->fileManager->hasWork() ||
            $this->streamManager->hasWatchers() ||
            $this->fiberManager->hasFibers();
    }

    public function processImmediateWork(): void
    {
        $this->tickHandler->processNextTickCallbacks();
        $this->fiberManager->processFibers();
        $this->fileManager->processFileOperations();
        $this->tickHandler->processDeferredCallbacks();
    }
}