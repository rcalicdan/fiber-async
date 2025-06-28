<?php

namespace Rcalicdan\FiberAsync;

/**
 * A promise that can be cancelled to clean up resources.
 */
class CancellablePromise extends AsyncPromise
{
    private ?string $timerId = null;
    private bool $cancelled = false;

    /**
     * Set the timer ID associated with this promise
     */
    public function setTimerId(string $timerId): void
    {
        $this->timerId = $timerId;
    }

    /**
     * Cancel the promise and its associated timer
     */
    public function cancel(): void
    {
        if (!$this->cancelled && $this->timerId !== null) {
            $this->cancelled = true;
            AsyncEventLoop::getInstance()->cancelTimer($this->timerId);
            $this->reject(new \Exception('Promise cancelled'));
        }
    }

    /**
     * Check if the promise has been cancelled
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
