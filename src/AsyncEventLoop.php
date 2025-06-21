<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\EventLoopInterface;
use Rcalicdan\FiberAsync\Services\FiberManager;
use Rcalicdan\FiberAsync\Services\HttpRequestManager;
use Rcalicdan\FiberAsync\Services\StreamManager;
use Rcalicdan\FiberAsync\Services\TimerManager;
use Rcalicdan\FiberAsync\Services\StreamMultiplexer;

class AsyncEventLoop implements EventLoopInterface
{
    private static ?AsyncEventLoop $instance = null;
    private bool $running = true;
    private array $tickCallbacks = [];
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private StreamMultiplexer $streamMultiplexer;
    private array $deferredCallbacks = [];
    private int $lastActivity = 0;

    private function __construct()
    {
        $this->timerManager = new TimerManager();
        $this->httpRequestManager = new HttpRequestManager();
        $this->streamManager = new StreamManager();
        $this->fiberManager = new FiberManager();
        $this->streamMultiplexer = new StreamMultiplexer();
        $this->lastActivity = time();
    }

    public static function getInstance(): AsyncEventLoop
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function addTimer(float $delay, callable $callback): string
    {
        return $this->timerManager->addTimer($delay, $callback);
    }

    public function addHttpRequest(string $url, array $options, callable $callback): void
    {
        $this->httpRequestManager->addHttpRequest($url, $options, $callback);
    }

    public function addStreamWatcher($stream, callable $callback): void
    {
        $this->streamManager->addStreamWatcher($stream, $callback);
    }

    public function addFiber(\Fiber $fiber): void
    {
        $this->fiberManager->addFiber($fiber);
    }

    public function nextTick(callable $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    public function defer(callable $callback): void
    {
        $this->deferredCallbacks[] = $callback;
    }

    public function run(): void
    {
        while ($this->running && $this->hasWork()) {
            $hasWork = $this->tick();
            
            if (!$hasWork) {
                $this->processIO();
            }
        }
    }

    private function tick(): bool
    {
        $workDone = false;

        // Process immediate callbacks first (highest priority)
        if ($this->processNextTickCallbacks()) {
            $workDone = true;
        }

        // Process ready timers
        if ($this->timerManager->processTimers()) {
            $workDone = true;
        }

        // Process HTTP requests (non-blocking check)
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        // Process custom streams
        if ($this->streamManager->processStreams()) {
            $workDone = true;
        }

        // Process fibers (resume suspended ones)
        if ($this->fiberManager->processFibers()) {
            $workDone = true;
        }

        // Process deferred callbacks (lowest priority)
        if ($this->processDeferredCallbacks()) {
            $workDone = true;
        }

        if ($workDone) {
            $this->lastActivity = time();
        }

        return $workDone;
    }

    private function processIO(): void
    {
        $read = $write = $except = [];
        
        // Collect streams that need monitoring
        $this->httpRequestManager->collectStreams($read, $write, $except);
        $this->streamManager->collectStreams($read, $write, $except);
        
        if (empty($read) && empty($write) && empty($except)) {
            // No I/O to monitor, minimal sleep to prevent CPU spinning
            usleep(100); // 0.1ms - only when completely idle
            return;
        }

        // Calculate timeout based on next timer
        $timeout = $this->calculateIOTimeout();
        
        // Non-blocking I/O multiplexing
        $result = stream_select($read, $write, $except, 0, $timeout);
        
        if ($result > 0) {
            // Handle ready streams
            $this->httpRequestManager->handleReadyStreams($read, $write);
            $this->streamManager->handleReadyStreams($read, $write);
        }
    }

    private function calculateIOTimeout(): int
    {
        $nextTimer = $this->timerManager->getNextTimerDelay();
        
        if ($nextTimer === null) {
            return 1000; 
        }
        
        return min(1000, (int)($nextTimer * 1000000));
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function processNextTickCallbacks(): bool
    {
        if (empty($this->tickCallbacks)) {
            return false;
        }

        $callbacks = $this->tickCallbacks;
        $this->tickCallbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('NextTick callback error: ' . $e->getMessage());
            }
        }

        return true;
    }

    private function processDeferredCallbacks(): bool
    {
        if (empty($this->deferredCallbacks)) {
            return false;
        }

        $callbacks = $this->deferredCallbacks;
        $this->deferredCallbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('Deferred callback error: ' . $e->getMessage());
            }
        }

        return true;
    }

    private function hasWork(): bool
    {
        return $this->timerManager->hasTimers() ||
               $this->httpRequestManager->hasRequests() ||
               $this->streamManager->hasWatchers() ||
               $this->fiberManager->hasFibers() ||
               !empty($this->tickCallbacks) ||
               !empty($this->deferredCallbacks);
    }

    public function isIdle(): bool
    {
        return !$this->hasWork() || (time() - $this->lastActivity) > 5;
    }
}