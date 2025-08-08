<?php

namespace Rcalicdan\FiberAsync\Promise;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

/**
 * A promise that can be cancelled to clean up resources.
 * 
 * @template TValue
 * @extends Promise<TValue>
 */
class CancellablePromise extends Promise
{
    private ?string $timerId = null;
    private bool $cancelled = false;
    
    /**
     * @var callable(): void|null
     */
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

            if ($this->cancelHandler !== null) {
                try {
                    ($this->cancelHandler)();
                } catch (\Throwable $e) {
                    error_log('Cancel handler error: '.$e->getMessage());
                }
            }

            if ($this->timerId !== null) {
                EventLoop::getInstance()->cancelTimer($this->timerId);
            }

            $this->reject(new \Exception('Promise cancelled'));
        }
    }

    /**
     * @param callable(): void $handler
     */
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