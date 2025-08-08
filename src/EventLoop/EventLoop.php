<?php

namespace Rcalicdan\FiberAsync\EventLoop;

use Fiber;
use Rcalicdan\FiberAsync\EventLoop\Handlers\ActivityHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\SleepHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\StateHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\TickHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\WorkHandler;
use Rcalicdan\FiberAsync\EventLoop\Interfaces\EventLoopInterface;
use Rcalicdan\FiberAsync\EventLoop\Managers\FiberManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\FileManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\SocketManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\StreamManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\TimerManager;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\StreamWatcher;

/**
 * Main event loop implementation for asynchronous operations using PHP Fibers.
 */
class EventLoop implements EventLoopInterface
{
    /**
     * @var EventLoop|null Singleton instance of the event loop
     */
    private static ?EventLoop $instance = null;

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
    private SocketManager $socketManager;

    private int $iterationCount = 0;
    private float $lastOptimizationCheck = 0;
    private const OPTIMIZATION_INTERVAL = 1.0;
    private const MAX_ITERATIONS = 1000000; // Reset counter at 1M iterations

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
        $this->socketManager = new SocketManager;

        $this->workHandler = new WorkHandler(
            timerManager: $this->timerManager,
            httpRequestManager: $this->httpRequestManager,
            streamManager: $this->streamManager,
            fiberManager: $this->fiberManager,
            tickHandler: $this->tickHandler,
            fileManager: $this->fileManager,
            socketManager: $this->socketManager,
        );

        $this->sleepHandler = new SleepHandler(
            $this->timerManager,
            $this->fiberManager
        );
    }

    public function getSocketManager(): SocketManager
    {
        return $this->socketManager;
    }

    /**
     * Get the singleton instance of the event loop.
     *
     * Creates a new instance if one doesn't exist, otherwise returns
     * the existing instance to ensure only one event loop runs per process.
     *
     * @return EventLoop The singleton event loop instance
     */
    public static function getInstance(): EventLoop
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
     * @param string $url The URL to request.
     * @param array<int, mixed> $options cURL options for the request, using CURLOPT_* constants as keys.
     * @param callable $callback Function to execute when request completes.
     * @return string A unique ID for the request.
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
    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        return $this->streamManager->addStreamWatcher($stream, $callback, $type);
    }

    public function removeStreamWatcher(string $watcherId): bool
    {
        return $this->streamManager->removeStreamWatcher($watcherId);
    }

    /**
     * Add a fiber to be managed by the event loop.
     *
     * @param Fiber<null, mixed, mixed, mixed> $fiber The fiber instance to add to the loop.
     * @return void
     */
    public function addFiber(Fiber $fiber): void
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
            $this->iterationCount++;
            $hasImmediateWork = $this->tick();

            // Adaptive optimization check
            if ($this->shouldOptimize()) {
                $this->optimizeLoop();
            }

            if ($this->sleepHandler->shouldSleep($hasImmediateWork)) {
                $sleepTime = $this->sleepHandler->calculateOptimalSleep();
                $this->sleepHandler->sleep($sleepTime);
            }

            // Reset iteration counter to prevent overflow
            if ($this->iterationCount >= self::MAX_ITERATIONS) {
                $this->iterationCount = 0;
            }
        }
    }

    private function shouldOptimize(): bool
    {
        $now = microtime(true);

        return ($now - $this->lastOptimizationCheck) > self::OPTIMIZATION_INTERVAL;
    }

    private function optimizeLoop(): void
    {
        $this->lastOptimizationCheck = microtime(true);

        // Trigger garbage collection periodically to prevent memory buildup
        if ($this->iterationCount % 1000 === 0) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
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
     *
     * @param  array<string, mixed>  $options
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
     *
     * @param  array<string, mixed>  $options
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

    /**
     * Get current iteration count (useful for debugging/monitoring)
     */
    public function getIterationCount(): int
    {
        return $this->iterationCount;
    }

    /**
     * Resets the singleton instance. Primarily for testing purposes.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
