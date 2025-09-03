<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Uv;

use Rcalicdan\FiberAsync\EventLoop\Managers\UVManager;

/**
 * UV-based event loop handler for efficient event processing.
 */
final class UVEventLoopHandler implements EventLoopHandlerInterface
{
    private UVManager $uvManager;
    private bool $running = false;
    private bool $shouldStop = false;

    public function __construct()
    {
        $this->uvManager = UVManager::getInstance();
    }

    public function run(callable $workCallback): void
    {
        if (!$this->uvManager->isAvailable()) {
            throw new \RuntimeException('UV extension is not available');
        }

        $this->running = true;
        $this->shouldStop = false;

        while ($this->running && !$this->shouldStop) {
            $hasCustomWork = $workCallback();

            $uvHasWork = $this->uvManager->runLoop(\UV::RUN_NOWAIT) > 0;

            if (!$hasCustomWork && !$uvHasWork) {
                $this->uvManager->runLoop(\UV::RUN_ONCE);
            }

            if (!$this->uvManager->isLoopAlive() && !$hasCustomWork) {
                usleep(100); 
            }
        }

        $this->running = false;
    }

    public function stop(): void
    {
        $this->shouldStop = true;
        $this->uvManager->stopLoop();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get event loop statistics
     */
    public function getLoopStats(): array
    {
        return [
            'is_running' => $this->running,
            'should_stop' => $this->shouldStop,
            'loop_alive' => $this->uvManager->isLoopAlive(),
        ] + $this->uvManager->getStats();
    }
}