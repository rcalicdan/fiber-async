<?php
namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;
use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;
use Rcalicdan\FiberAsync\Managers\SocketManager; // Add this
final readonly class WorkHandler
{
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private TickHandler $tickHandler;
    private FileManager $fileManager;
    private SocketManager $socketManager; // Add this
    public function __construct(
        TimerManager $timerManager,
        HttpRequestManager $httpRequestManager,
        StreamManager $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler,
        FileManager $fileManager,
        SocketManager $socketManager, // Add this
    ) {
        $this->timerManager = $timerManager;
        $this->httpRequestManager = $httpRequestManager;
        $this->streamManager = $streamManager;
        $this->fiberManager = $fiberManager;
        $this->tickHandler = $tickHandler;
        $this->fileManager = $fileManager;
        $this->socketManager = $socketManager; // Add this
    }
    public function hasWork(): bool
    {
        return $this->tickHandler->hasTickCallbacks() ||
            $this->tickHandler->hasDeferredCallbacks() ||
            $this->timerManager->hasTimers() ||
            $this->httpRequestManager->hasRequests() ||
            $this->fileManager->hasWork() ||
            $this->streamManager->hasWatchers() ||
            $this->socketManager->hasWatchers() || // Add this
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
        // Socket processing should happen early, like HTTP requests
        if ($this->socketManager->processSockets()) { // Add this block
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