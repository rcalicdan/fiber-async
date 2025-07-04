<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\EventLoopInterface;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\ActivityHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\SleepHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\StateHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\TickHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\WorkHandler;
use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;

/**
 * Main event loop implementation for asynchronous operations using PHP Fibers.
 *
 * This class provides a singleton event loop that coordinates the execution of
 * various asynchronous operations including timers, HTTP requests, streams, and
 * fibers. It manages the lifecycle of the event loop and provides methods for
 * scheduling different types of asynchronous work.
 *
 * The event loop uses a tick-based processing model where each iteration processes
 * all available work before optionally sleeping to reduce CPU usage.
 */
class AsyncEventLoop implements EventLoopInterface
{
    /**
     * @var AsyncEventLoop|null Singleton instance of the event loop
     */
    private static ?AsyncEventLoop $instance = null;

    /**
     * @var TimerManager Manages timer-based delayed callbacks
     */
    private TimerManager $timerManager;

    /**
     * @var HttpRequestManager Manages asynchronous HTTP requests
     */
    private HttpRequestManager $httpRequestManager;

    /**
     * @var StreamManager Manages stream I/O operations
     */
    private StreamManager $streamManager;

    /**
     * @var FiberManager Manages PHP Fiber execution and lifecycle
     */
    private FiberManager $fiberManager;

    /**
     * @var TickHandler Handles next-tick and deferred callback processing
     */
    private TickHandler $tickHandler;

    /**
     * @var WorkHandler Coordinates work processing across all components
     */
    private WorkHandler $workHandler;

    /**
     * @var SleepHandler Manages sleep optimization for the event loop
     */
    private SleepHandler $sleepHandler;

    /**
     * @var ActivityHandler Tracks event loop activity for idle detection
     */
    private ActivityHandler $activityHandler;

    /**
     * @var StateHandler Manages the running state of the event loop
     */
    private StateHandler $stateHandler;

    /**
     * @var FileManager Manages file operations
     */
    private FileManager $fileManager;

    /**
     * Initialize the event loop with all required managers and handlers.
     *
     * Private constructor to enforce singleton pattern. Sets up all managers
     * and handlers with proper dependency injection.
     */
    private function __construct()
    {
        $this->timerManager = new TimerManager;
        $this->httpRequestManager = new HttpRequestManager;
        $this->streamManager = new StreamManager;
        $this->fiberManager = new FiberManager;
        $this->tickHandler = new TickHandler;
        $this->activityHandler = new ActivityHandler;
        $this->stateHandler = new StateHandler;
        $this->fileManager = new FileManager;

        // Initialize handlers that depend on managers
        $this->workHandler = new WorkHandler(
            $this->timerManager,
            $this->httpRequestManager,
            $this->streamManager,
            $this->fiberManager,
            $this->tickHandler,
            $this->fileManager,
        );

        $this->sleepHandler = new SleepHandler(
            $this->timerManager,
            $this->fiberManager
        );
    }

    /**
     * Get the singleton instance of the event loop.
     *
     * Creates a new instance if one doesn't exist, otherwise returns
     * the existing instance to ensure only one event loop runs per process.
     *
     * @return AsyncEventLoop The singleton event loop instance
     */
    public static function getInstance(): AsyncEventLoop
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Schedule a timer to execute a callback after a specified delay.
     *
     * @param  float  $delay  Delay in seconds before executing the callback
     * @param  callable  $callback  Function to execute when timer expires
     * @return string Unique identifier for the timer
     */
    public function addTimer(float $delay, callable $callback): string
    {
        return $this->timerManager->addTimer($delay, $callback);
    }

    /**
     * Cancel a previously scheduled timer.
     *
     * @param  string  $timerId  The timer ID returned by addTimer()
     * @return bool True if timer was cancelled, false if not found
     */
    public function cancelTimer(string $timerId): bool
    {
        return $this->timerManager->cancelTimer($timerId);
    }

    /**
     * Schedule an asynchronous HTTP request.
     *
     * @param  string  $url  The URL to request
     * @param  array  $options  HTTP request options (headers, method, body, etc.)
     * @param  callable  $callback  Function to execute when request completes
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        return $this->httpRequestManager->addHttpRequest($url, $options, $callback);
    }

    /**
     * Cancel a previously scheduled HTTP request.
     *
     * @param  string  $requestId  The request ID returned by addHttpRequest()
     * @return bool True if request was cancelled, false if not found
     */
    public function cancelHttpRequest(string $requestId): bool
    {
        return $this->httpRequestManager->cancelHttpRequest($requestId);
    }

    /**
     * Add a stream watcher for I/O operations.
     *
     * @param  resource  $stream  The stream resource to watch
     * @param  callable  $callback  Function to execute when stream has data
     */
    public function addStreamWatcher($stream, callable $callback): void
    {
        $this->streamManager->addStreamWatcher($stream, $callback);
    }

    /**
     * Add a fiber to be managed by the event loop.
     *
     * @param  \Fiber  $fiber  The fiber instance to add to the loop
     */
    public function addFiber(\Fiber $fiber): void
    {
        $this->fiberManager->addFiber($fiber);
    }

    /**
     * Schedule a callback to run on the next event loop tick.
     *
     * Next-tick callbacks have the highest priority and execute before
     * any other work in the next loop iteration.
     *
     * @param  callable  $callback  Function to execute on next tick
     */
    public function nextTick(callable $callback): void
    {
        $this->tickHandler->addNextTick($callback);
    }

    /**
     * Schedule a callback to run after the current work phase.
     *
     * Deferred callbacks run after all immediate work is processed
     * but before the loop sleeps or waits for events.
     *
     * @param  callable  $callback  Function to execute when deferred
     */
    public function defer(callable $callback): void
    {
        $this->tickHandler->addDeferred($callback);
    }

    /**
     * Start the main event loop execution.
     *
     * Continues processing work until the loop is stopped or no more
     * work is available. Uses sleep optimization to reduce CPU usage
     * when waiting for events.
     */
    public function run(): void
    {
        while ($this->stateHandler->isRunning() && $this->workHandler->hasWork()) {
            $hasImmediateWork = $this->tick();

            // Only sleep if there's no immediate work and no fibers waiting
            if ($this->sleepHandler->shouldSleep($hasImmediateWork)) {
                $sleepTime = $this->sleepHandler->calculateOptimalSleep();
                $this->sleepHandler->sleep($sleepTime);
            }
        }
    }

    /**
     * Process one iteration of the event loop.
     *
     * Executes all available work and updates activity tracking.
     * This is the core processing method called by the main run loop.
     *
     * @return bool True if work was processed, false if no work was available
     */
    private function tick(): bool
    {
        $workDone = $this->workHandler->processWork();

        if ($workDone) {
            $this->activityHandler->updateLastActivity();
        }

        return $workDone;
    }

    /**
     * Stop the event loop execution.
     *
     * Gracefully stops the event loop after the current iteration completes.
     * The loop will exit when it next checks the running state.
     */
    public function stop(): void
    {
        $this->stateHandler->stop();
    }

    /**
     * Check if the event loop is currently idle.
     *
     * An idle loop has no pending work or has been inactive for an
     * extended period. Useful for determining system load state.
     *
     * @return bool True if the loop is idle, false if actively processing
     */
    public function isIdle(): bool
    {
        return ! $this->workHandler->hasWork() || $this->activityHandler->isIdle();
    }

    /**
     * Schedule an asynchronous file operation
     */
    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string {
        return $this->fileManager->addFileOperation($type, $path, $data, $callback, $options);
    }

    /**
     * Cancel a file operation
     */
    public function cancelFileOperation(string $operationId): bool
    {
        return $this->fileManager->cancelFileOperation($operationId);
    }

    /**
     * Add a file watcher
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        return $this->fileManager->addFileWatcher($path, $callback, $options);
    }

    /**
     * Remove a file watcher
     */
    public function removeFileWatcher(string $watcherId): bool
    {
        return $this->fileManager->removeFileWatcher($watcherId);
    }
}
