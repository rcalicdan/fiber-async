<?php


namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Async;

/**
 * A simple, non-reentrant, async-friendly Mutex (Mutual Exclusion lock).
 */
class Mutex
{
    private bool $locked = false;
    private \SplQueue $queue;

    public function __construct()
    {
        $this->queue = new \SplQueue;
    }

    public function acquire(): PromiseInterface
    {
        if (! $this->locked) {
            $this->locked = true;

            return Async::resolve($this); // Lock acquired immediately
        }

        // If locked, return a promise that will be resolved when the lock is released.
        $promise = new AsyncPromise;
        $this->queue->enqueue($promise);

        return $promise;
    }

    public function release(): void
    {
        if ($this->queue->isEmpty()) {
            $this->locked = false;
        } else {
            // Give the lock to the next waiting promise in the queue.
            $promise = $this->queue->dequeue();
            $promise->resolve($this);
        }
    }
}
