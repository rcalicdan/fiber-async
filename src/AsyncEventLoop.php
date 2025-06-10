<?php

namespace TrueAsync;

use TrueAsync\Interfaces\EventLoopInterface;
use TrueAsync\Services\TimerManager;
use TrueAsync\Services\HttpRequestManager;
use TrueAsync\Services\StreamManager;
use TrueAsync\Services\FiberManager;

class AsyncEventLoop implements EventLoopInterface
{
    private static ?AsyncEventLoop $instance = null;
    private bool $running = true;
    private array $tickCallbacks = [];
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;

    private function __construct()
    {
        $this->timerManager = new TimerManager();
        $this->httpRequestManager = new HttpRequestManager();
        $this->streamManager = new StreamManager();
        $this->fiberManager = new FiberManager();
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

    public function run(): void
    {
        while ($this->running && $this->hasWork()) {
            $this->processNextTickCallbacks();
            $this->timerManager->processTimers();
            $this->httpRequestManager->processRequests();
            $this->streamManager->processStreams();
            $this->fiberManager->processFibers();

            usleep(1000); 
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function processNextTickCallbacks(): void
    {
        while (!empty($this->tickCallbacks)) {
            $callback = array_shift($this->tickCallbacks);
            $callback();
        }
    }

    private function hasWork(): bool
    {
        return $this->timerManager->hasTimers() ||
               $this->httpRequestManager->hasRequests() ||
               $this->streamManager->hasWatchers() ||
               $this->fiberManager->hasFibers() ||
               !empty($this->tickCallbacks);
    }
}