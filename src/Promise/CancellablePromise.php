<?php

namespace Rcalicdan\FiberAsync\Promise;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * A promise that can be cancelled to clean up resources.
 *
 * @template TValue
 *
 * @extends Promise<TValue>
 *
 * @implements CancellablePromiseInterface<TValue>
 */
class CancellablePromise extends Promise implements CancellablePromiseInterface
{
    private ?string $timerId = null;
    private bool $cancelled = false;

    /**
     * {@inheritdoc}
     */
    private $cancelHandler = null;

    /**
     * {@inheritdoc}
     */
    public function setTimerId(string $timerId): void
    {
        $this->timerId = $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(): void
    {
        if (! $this->cancelled) {
            $this->cancelled = true;

            if ($this->cancelHandler) {
                try {
                    ($this->cancelHandler)();
                } catch (\Throwable $e) {
                    error_log('Cancel handler error: ' . $e->getMessage());
                }
            }

            if ($this->timerId !== null) {
                EventLoop::getInstance()->cancelTimer($this->timerId);
            }

            $this->reject(new \Exception('Promise cancelled'));
        }
    }

    public function setCancelHandler(callable $handler): void
    {
        $this->cancelHandler = $handler;
    }

    /**
     * {@inheritdoc}
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
