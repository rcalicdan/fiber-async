<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;

/**
 * Coordinates work processing across all event loop components.
 *
 * This handler orchestrates the execution order of different types of work
 * in the event loop, including HTTP requests, fibers, timers, streams, and
 * callback processing. The order is optimized for performance and correctness.
 */
final readonly class WorkHandler
{
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private TickHandler $tickHandler;
    private FileManager $fileManager;

    /**
     * @param  TimerManager  $timerManager  Handles timer-based operations
     * @param  HttpRequestManager  $httpRequestManager  Handles HTTP request processing
     * @param  StreamManager  $streamManager  Handles stream I/O operations
     * @param  FiberManager  $fiberManager  Handles fiber execution and management
     * @param  TickHandler  $tickHandler  Handles next-tick and deferred callbacks
     * @param  FileManager  $fileManager  Handles file operations
     */
    public function __construct(
        TimerManager $timerManager,
        HttpRequestManager $httpRequestManager,
        StreamManager $streamManager,
        FiberManager $fiberManager,
        TickHandler $tickHandler,
        FileManager $fileManager,
    ) {
        $this->timerManager = $timerManager;
        $this->httpRequestManager = $httpRequestManager;
        $this->streamManager = $streamManager;
        $this->fiberManager = $fiberManager;
        $this->tickHandler = $tickHandler;
        $this->fileManager = $fileManager;
    }

    /**
     * Check if there's any pending work across all components.
     *
     * This aggregates work status from all managers to determine if the
     * event loop should continue processing or can sleep/exit.
     *
     * @return bool True if any component has pending work, false otherwise
     */
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

    /**
     * Process all pending work in the correct order.
     *
     * The processing order is carefully designed:
     * 1. Next-tick callbacks (highest priority)
     * 2. HTTP requests (start new requests immediately)
     * 3. Fibers (may add more HTTP requests)
     * 4. HTTP requests again (process newly added requests)
     * 5. File operations (read/write/stat/delete)
     * 6. Timers (scheduled callbacks)
     * 7. Streams (I/O operations)
     * 8. Deferred callbacks (cleanup/low priority)
     *
     * @return bool True if any work was processed, false if no work was done
     */
    public function processWork(): bool
    {
        $workDone = false;

        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        if ($this->httpRequestManager->processRequests()) {
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
