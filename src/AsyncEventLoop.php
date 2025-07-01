<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\EventLoopInterface;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\SleepHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\StateHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\TickHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\WorkHandler;
use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\FileManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;

class AsyncEventLoop implements EventLoopInterface
{
    private static ?AsyncEventLoop $instance = null;
    private TimerManager $timerManager;
    private HttpRequestManager $httpRequestManager;
    private StreamManager $streamManager;
    private FiberManager $fiberManager;
    private TickHandler $tickHandler;
    private WorkHandler $workHandler;
    private SleepHandler $sleepHandler;
    private StateHandler $stateHandler;
    private FileManager $fileManager;

    private function __construct()
    {
        $this->timerManager = new TimerManager;
        $this->httpRequestManager = new HttpRequestManager;
        $this->streamManager = new StreamManager;
        $this->fiberManager = new FiberManager;
        $this->tickHandler = new TickHandler;
        $this->stateHandler = new StateHandler;
        $this->fileManager = new FileManager();
        $this->sleepHandler = new SleepHandler($this->timerManager, $this->fiberManager);
        $this->workHandler = new WorkHandler($this->timerManager, $this->httpRequestManager, $this->streamManager, $this->fiberManager, $this->tickHandler, $this->fileManager);
    }

    public static function getInstance(): AsyncEventLoop
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function run(): void
    {
        $this->stateHandler->start();

        while ($this->stateHandler->isRunning()) {
            $this->workHandler->processImmediateWork();

            if (!$this->workHandler->hasWork()) {
                $this->stateHandler->stop();
                continue;
            }

            $read = $this->streamManager->getReadStreams();
            $write = [];
            $except = null;

            $timerDelay = $this->timerManager->getNextTimerDelay();
            $curlDelay = $this->httpRequestManager->getSelectTimeout();

            $delay = null;
            if ($timerDelay !== null && $curlDelay !== null) {
                $delay = min($timerDelay, $curlDelay);
            } elseif ($timerDelay !== null) {
                $delay = $timerDelay;
            } else {
                $delay = $curlDelay;
            }

            if (empty($read) && empty($write)) {
                $sleepDuration = ($delay === null) ? 1000 : (int) max(0, $delay * 1_000_000);
                $this->sleepHandler->sleep($sleepDuration);
            } else {
                $tv_sec = ($delay === null) ? 1 : (int) $delay;
                $tv_usec = ($delay === null) ? 0 : (int) (($delay - $tv_sec) * 1_000_000);
                @stream_select($read, $write, $except, $tv_sec, $tv_usec);
            }

            $this->streamManager->processReadyStreams($read);
            $this->httpRequestManager->processRequests();
            $this->timerManager->processTimers();
        }
    }

    public function stop(): void
    {
        $this->stateHandler->stop();
    }

    public function isIdle(): bool
    {
        return !$this->workHandler->hasWork();
    }

    public function addTimer(float $delay, callable $callback): string
    {
        return $this->timerManager->addTimer($delay, $callback);
    }

    public function cancelTimer(string $timerId): bool
    {
        return $this->timerManager->cancelTimer($timerId);
    }

    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        return $this->httpRequestManager->addHttpRequest($url, $options, $callback);
    }

    public function cancelHttpRequest(string $requestId): bool
    {
        return $this->httpRequestManager->cancelHttpRequest($requestId);
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
        $this->tickHandler->addNextTick($callback);
    }

    public function defer(callable $callback): void
    {
        $this->tickHandler->addDeferred($callback);
    }

    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string {
        return $this->fileManager->addFileOperation($type, $path, $data, $callback, $options);
    }

    public function cancelFileOperation(string $operationId): bool
    {
        return $this->fileManager->cancelFileOperation($operationId);
    }

    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        return $this->fileManager->addFileWatcher($path, $callback, $options);
    }

    public function removeFileWatcher(string $watcherId): bool
    {
        return $this->fileManager->removeFileWatcher($watcherId);
    }
}
