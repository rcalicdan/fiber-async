<?php

namespace Rcalicdan\FiberAsync\EventLoop\Factories;

use Rcalicdan\FiberAsync\EventLoop\Detectors\UVDetector;
use Rcalicdan\FiberAsync\EventLoop\Handlers\SleepHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\UVWorkHandler;
use Rcalicdan\FiberAsync\EventLoop\Handlers\WorkHandler;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Uv\UVSleepHandler;
use Rcalicdan\FiberAsync\EventLoop\Managers\SocketManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\StreamManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\TimerManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\UV\UVSocketManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\UV\UVStreamManager;
use Rcalicdan\FiberAsync\EventLoop\Managers\UV\UVTimerManager;

/**
 * Factory for creating UV-aware or fallback components
 */
final class EventLoopComponentFactory
{
    private static $uvLoop = null;

    public static function createTimerManager(): TimerManager
    {
        if (UVDetector::isUvAvailable()) {
            return new UVTimerManager(self::getUvLoop());
        }

        return new TimerManager;
    }

    public static function createStreamManager(): StreamManager
    {
        if (UvDetector::isUvAvailable()) {
            return new UVStreamManager(self::getUvLoop());
        }

        return new StreamManager;
    }

    public static function createSocketManager(): SocketManager
    {
        if (UVDetector::isUvAvailable()) {
            return new UVSocketManager(self::getUvLoop());
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
        if (UVDetector::isUvAvailable()) {
            return new UVWorkHandler(
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
        if (UVDetector::isUvAvailable()) {
            return new UVSleepHandler(
                $timerManager,
                $fiberManager,
                self::getUvLoop()
            );
        }

        return new SleepHandler($timerManager, $fiberManager);
    }

    private static function getUvLoop()
    {
        if (self::$uvLoop === null && UVDetector::isUvAvailable()) {
            self::$uvLoop = \uv_default_loop();
        }

        return self::$uvLoop;
    }

    public static function resetUvLoop(): void
    {
        self::$uvLoop = null;
    }
}
