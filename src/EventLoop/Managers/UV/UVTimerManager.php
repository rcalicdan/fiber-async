<?php

namespace Rcalicdan\FiberAsync\EventLoop\Managers\Uv;

use Rcalicdan\FiberAsync\EventLoop\Managers\TimerManager;

/**
 * UV-based timer manager using libuv for high-performance timing
 */
final class UvTimerManager extends TimerManager
{
    private $uvLoop;
    private array $uvTimers = [];

    public function __construct($uvLoop = null)
    {
        parent::__construct();
        $this->uvLoop = $uvLoop ?? \uv_default_loop();
    }

    public function addTimer(float $delay, callable $callback): string
    {
        $timerId = parent::addTimer($delay, $callback);
        
        $uvTimer = \uv_timer_init($this->uvLoop);
        $this->uvTimers[$timerId] = $uvTimer;
        
        $delayMs = (int)($delay * 1000);
        
        \uv_timer_start($uvTimer, $delayMs, 0, function($timer) use ($callback, $timerId) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log("UV Timer callback error: " . $e->getMessage());
            } finally {
                \uv_close($timer);
                unset($this->uvTimers[$timerId]);
                parent::cancelTimer($timerId);
            }
        });
        
        return $timerId;
    }

    public function cancelTimer(string $timerId): bool
    {
        $parentResult = parent::cancelTimer($timerId);
        
        if (isset($this->uvTimers[$timerId])) {
            $uvTimer = $this->uvTimers[$timerId];
            \uv_timer_stop($uvTimer);
            \uv_close($uvTimer);
            unset($this->uvTimers[$timerId]);
            return true;
        }
        
        return $parentResult;
    }

    /**
     * Process timers - MUST return bool to match parent signature
     */
    public function processTimers(): bool
    {
        $parentWorkDone = parent::processTimers();
        
        return !empty($this->uvTimers) || $parentWorkDone;
    }

    public function hasTimers(): bool
    {
        return !empty($this->uvTimers) || parent::hasTimers();
    }

    public function clearAllTimers(): void
    {
        foreach ($this->uvTimers as $uvTimer) {
            \uv_timer_stop($uvTimer);
            \uv_close($uvTimer);
        }
        $this->uvTimers = [];
        
        parent::clearAllTimers();
    }

    public function getNextTimerDelay(): ?float
    {
        if (!empty($this->uvTimers)) {
            return null; 
        }
        
        return parent::getNextTimerDelay();
    }
}