<?php

namespace Rcalicdan\FiberAsync\EventLoop\IOHandlers\Uv;

use Rcalicdan\FiberAsync\EventLoop\Interfaces\TimerHandlerInterface;
use UVTimer;

/**
 * UV-based timer handler for high-performance timer management.
 */
final class UVTimerHandler implements TimerHandlerInterface
{
    private UVManager $uvManager;
    private array $timers = [];

    public function __construct()
    {
        $this->uvManager = UVManager::getInstance();
    }

    public function addTimer(float $delay, callable $callback): string
    {
        if (!$this->uvManager->isAvailable()) {
            throw new \RuntimeException('UV extension is not available');
        }

        $timerId = uniqid('uv_timer_', true);
        $delayMs = max(1, (int)($delay * 1000));
        
        $loop = $this->uvManager->getLoop();
        $timer = uv_timer_init($loop);
        
        uv_timer_start($timer, $delayMs, 0, function() use ($callback, $timerId) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("UV Timer callback error: " . $e->getMessage());
            } finally {
                $this->cleanupTimer($timerId);
            }
        });

        $this->timers[$timerId] = [
            'timer' => $timer,
            'created_at' => microtime(true),
            'delay' => $delay,
            'callback' => $callback
        ];

        $this->uvManager->incrementTimerCount();
        
        return $timerId;
    }

    public function cancelTimer(string $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }

        $this->cleanupTimer($timerId);
        return true;
    }

    public function processTimers(): bool
    {
        // UV handles timer processing automatically through the event loop
        // This method exists for interface compatibility
        return !empty($this->timers);
    }

    public function hasTimers(): bool
    {
        return !empty($this->timers);
    }

    public function clearAllTimers(): void
    {
        foreach (array_keys($this->timers) as $timerId) {
            $this->cleanupTimer($timerId);
        }
    }

    public function getNextTimerDelay(): ?float
    {
        if (empty($this->timers)) {
            return null;
        }

        $now = microtime(true);
        $minDelay = PHP_FLOAT_MAX;

        foreach ($this->timers as $timer) {
            $elapsed = $now - $timer['created_at'];
            $remaining = $timer['delay'] - $elapsed;

            if ($remaining < $minDelay) {
                $minDelay = $remaining;
            }
        }

        return max(0.0, $minDelay);
    }

    /**
     * Get timer statistics
     */
    public function getTimerStats(): array
    {
        return [
            'active_timers' => count($this->timers),
            'next_timer_delay' => $this->getNextTimerDelay(),
        ];
    }

    private function cleanupTimer(string $timerId): void
    {
        if (!isset($this->timers[$timerId])) {
            return;
        }

        $timer = $this->timers[$timerId]['timer'];
        
        try {
            uv_timer_stop($timer);
            uv_close($timer);
        } catch (\Throwable $e) {
            error_log("Error cleaning up UV timer: " . $e->getMessage());
        }
        
        unset($this->timers[$timerId]);
    }
}