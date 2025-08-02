<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

/**
 * Centralized cleanup manager for all singletons and resources.
 * 
 * Provides a single point of control for cleaning up all static instances,
 * preventing memory leaks without requiring multiple manual reset calls.
 */
class CleanupManager
{
    /**
     * Perform complete cleanup of all singletons and resources.
     * 
     * This method handles cleanup in the correct order to prevent
     * conflicts and ensures all resources are properly released.
     */
    public static function cleanup(): void
    {
        AsyncHttp::reset();
        Async::reset();
        AsyncLoop::reset();
        AsyncSocket::reset();
        Promise::reset();
        Timer::reset();
        AsyncPDO::reset();
        AsyncDB::reset();
        AsyncFile::reset();
        EventLoop::reset();

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}
