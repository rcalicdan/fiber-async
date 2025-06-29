<?php

namespace Rcalicdan\FiberAsync;

/**
 * A promise that can be cancelled to clean up resources.
 */
class CancellablePromise extends AsyncPromise
{
    private ?string $timerId = null;
    private bool $cancelled = false;
    private $cancelHandler = null;

    /**
     * Set the timer ID associated with this promise
     */
    public function setTimerId(string $timerId): void
    {
        $this->timerId = $timerId;
    }

    public function cancel(): void
    {
        if (! $this->cancelled) {
            $this->cancelled = true;

            if ($this->cancelHandler) {
                try {
                    ($this->cancelHandler)();
                } catch (\Throwable $e) {
                    error_log('Cancel handler error: '.$e->getMessage());
                }
            }

            if ($this->timerId !== null) {
                AsyncEventLoop::getInstance()->cancelTimer($this->timerId);
            }

            $this->reject(new \Exception('Promise cancelled'));
        }
    }

    public function setCancelHandler(callable $handler): void
    {
        $this->cancelHandler = $handler;
    }

    /**
     * Check if the promise has been cancelled
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
