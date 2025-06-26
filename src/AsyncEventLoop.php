<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\EventLoopInterface;
use Rcalicdan\FiberAsync\Managers\FiberManager;
use Rcalicdan\FiberAsync\Managers\HttpRequestManager;
use Rcalicdan\FiberAsync\Managers\StreamManager;
use Rcalicdan\FiberAsync\Managers\TimerManager;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler\TickHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler\WorkHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler\SleepHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler\ActivityHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler\StateHandler;

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
    private ActivityHandler $activityHandler;
    private StateHandler $stateHandler;

    private function __construct()
    {
        $this->timerManager = new TimerManager;
        $this->httpRequestManager = new HttpRequestManager;
        $this->streamManager = new StreamManager;
        $this->fiberManager = new FiberManager;
        $this->tickHandler = new TickHandler;
        $this->activityHandler = new ActivityHandler;
        $this->stateHandler = new StateHandler;

        // Initialize handlers that depend on managers
        $this->workHandler = new WorkHandler(
            $this->timerManager,
            $this->httpRequestManager,
            $this->streamManager,
            $this->fiberManager,
            $this->tickHandler
        );

        $this->sleepHandler = new SleepHandler(
            $this->timerManager,
            $this->fiberManager
        );
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
        $this->tickHandler->addNextTick($callback);
    }

    public function defer(callable $callback): void
    {
        $this->tickHandler->addDeferred($callback);
    }

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

    private function tick(): bool
    {
        $workDone = $this->workHandler->processWork();

        if ($workDone) {
            $this->activityHandler->updateLastActivity();
        }

        return $workDone;
    }

    public function stop(): void
    {
        $this->stateHandler->stop();
    }

    public function isIdle(): bool
    {
        return !$this->workHandler->hasWork() || $this->activityHandler->isIdle();
    }
}
