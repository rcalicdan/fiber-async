<?php

namespace Rcalicdan\FiberAsync\EventLoop\Factories;

use Rcalicdan\FiberAsync\EventLoop\Detectors\UvDetector;
use Rcalicdan\FiberAsync\EventLoop\Handlers\SleepHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\UvWorkHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\WorkHandler;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Uv\UvSleepHandler;
use Rcalicdan\FiberAsync\EventLoop\Managers\SocketManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\StreamManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\TimerManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\Uv\UvSocketManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\Uv\UvStreamManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\Uv\UvTimerManager;

/**
 * Factory for creating UV-aware or fallback components
 */
final class EventLoopComponentFactory
{
    private static $uvLoop = null;

    public static function createTimerManager(): TimerManager
    {
        if (UvDetector::isUvAvailable()) {
            return new UvTimerManager(self::getUvLoop());
        }

        return new TimerManager;
    }

    public static function createStreamManager(): StreamManager
    {
        if (UvDetector::isUvAvailable()) {
            return new UvStreamManager(self::getUvLoop());
        }

        return new StreamManager;
    }

    public static function createSocketManager(): SocketManager
    {
        if (UvDetector::isUvAvailable()) {
            return new UvSocketManager(self::getUvLoop());
        }

        return new SocketManager;
    }

    public static function createWorkHandler(
        $timerManager,
        $httpRequestManager,
        $streamManager,
        $fiberManager,
        $tickHandler,
        $fileManager,
        $socketManager
    ): WorkHandler {
        if (UvDetector::isUvAvailable()) {
            return new UvWorkHandler(
                self::getUvLoop(),
                $timerManager,
                $httpRequestManager,
                $streamManager,
                $fiberManager,
                $tickHandler,
                $fileManager,
                $socketManager
            );
        }

        return new WorkHandler(
            $timerManager,
            $httpRequestManager,
            $streamManager,
            $fiberManager,
            $tickHandler,
            $fileManager,
            $socketManager
        );
    }

    public static function createSleepHandler(
        $timerManager,
        $fiberManager
    ): SleepHandler {
        if (UvDetector::isUvAvailable()) {
            return new UvSleepHandler(
                $timerManager,
                $fiberManager,
                self::getUvLoop()
            );
        }

        return new SleepHandler($timerManager, $fiberManager);
    }

    private static function getUvLoop()
    {
        if (self::$uvLoop === null && UvDetector::isUvAvailable()) {
            self::$uvLoop = \uv_default_loop();
        }

        return self::$uvLoop;
    }

    public static function resetUvLoop(): void
    {
        self::$uvLoop = null;
    }
}
