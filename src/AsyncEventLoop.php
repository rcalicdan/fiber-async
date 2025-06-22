<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\EventLoopInterface;
use Rcalicdan\FiberAsync\Services\FiberManager;
use Rcalicdan\FiberAsync\Services\HttpRequestManager;
use Rcalicdan\FiberAsync\Services\StreamManager;
use Rcalicdan\FiberAsync\Services\TimerManager;

class AsyncEventLoop implements EventLoopInterface
{
    private static ?AsyncEventLoop $instance = null;
    private bool $running = true;
    private array $tickCallbacks = [];
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private array $deferredCallbacks = [];
    private int $lastActivity = 0;

    private function __construct()
    {
        $this->timerManager = new TimerManager;
        $this->httpRequestManager = new HttpRequestManager;
        $this->streamManager = new StreamManager;
        $this->fiberManager = new FiberManager;
        $this->lastActivity = time();
    }

    public static function getInstance(): AsyncEventLoop
    {
        if (self::$instance === null) {
            self::$instance = new self;
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
            $hasImmediateWork = $this->tick();

            // Only sleep if there's no immediate work and no fibers waiting
            if (! $hasImmediateWork && ! $this->fiberManager->hasActiveFibers()) {
                $sleepTime = $this->calculateOptimalSleep();
                if ($sleepTime > 0) {
                    usleep($sleepTime);
                }
            }
        }
    }

    private function tick(): bool
    {
        $workDone = false;

        // Process immediate callbacks first
        if ($this->processNextTickCallbacks()) {
            $workDone = true;
        }

        // Process timers
        if ($this->timerManager->processTimers()) {
            $workDone = true;
        }

        // Process HTTP requests (non-blocking)
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        // Process streams (non-blocking)
        if ($this->streamManager->processStreams()) {
            $workDone = true;
        }

        // Process fibers
        if ($this->fiberManager->processFibers()) {
            $workDone = true;
        }

        // Process deferred callbacks
        if ($this->processDeferredCallbacks()) {
            $workDone = true;
        }

        if ($workDone) {
            $this->lastActivity = time();
        }

        return $workDone;
    }

    private function calculateOptimalSleep(): int
    {
        $nextTimer = $this->timerManager->getNextTimerDelay();

        if ($nextTimer !== null) {
            // Sleep until next timer, but max 1ms
            return min(1000, (int) ($nextTimer * 1000000));
        }

        // Default minimal sleep
        return 100; // 0.1ms
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

        while (! empty($this->tickCallbacks)) {
            $callback = array_shift($this->tickCallbacks);

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
            ! empty($this->tickCallbacks) ||
            ! empty($this->deferredCallbacks);
    }

    public function isIdle(): bool
    {
        return ! $this->hasWork() || (time() - $this->lastActivity) > 5;
    }
}