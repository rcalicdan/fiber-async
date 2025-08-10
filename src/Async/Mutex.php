<?php

namespace Rcalicdan\FiberAsync\Async;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;

/**
 * A simple, non-reentrant, async-friendly Mutex (Mutual Exclusion lock).
 *
 * This Mutex implementation provides thread-safe access to shared resources in
 * an asynchronous environment. It uses promises to handle queuing and allows
 * multiple async operations to wait for lock acquisition without blocking.
 *
 * The mutex is non-reentrant, meaning the same task cannot acquire the lock
 * multiple times. Each acquire() call must be paired with a release() call.
 */
class Mutex
{
    /**
     * Whether the mutex is currently locked.
     */
    private bool $locked = false;

    /**
     * Queue of promises waiting to acquire the lock.
     *
     * When the mutex is locked, subsequent acquire() calls create promises
     * that are queued here. When the mutex is released, the next promise
     * in the queue is resolved with the mutex instance.
     *
     * @var \SplQueue<Promise<$this>>
     */
    private \SplQueue $queue;

    /**
     * Create a new Mutex instance.
     *
     * The mutex starts in an unlocked state with an empty queue.
     */
    public function __construct()
    {
        $this->queue = new \SplQueue;
    }

    /**
     * Acquire the mutex lock.
     *
     * If the mutex is not currently locked, it will be immediately acquired
     * and a resolved promise containing the mutex instance is returned.
     *
     * If the mutex is already locked, a pending promise is created and added
     * to the queue. The promise will be resolved when the mutex becomes available.
     *
     * The returned promise resolves with the mutex instance, which should be
     * used to release the lock when the critical section is complete.
     *
     * @return PromiseInterface<$this> A promise that resolves with this mutex instance
     */
    public function acquire(): PromiseInterface
    {
        if (! $this->locked) {
            $this->locked = true;

            return Promise::resolved($this);
        }

        // Mutex is locked, create a pending promise and queue it
        /** @var Promise<$this> $promise */
        $promise = new Promise;
        $this->queue->enqueue($promise);

        return $promise;
    }

    /**
     * Release the mutex lock.
     *
     * This method releases the current lock and processes the next waiting
     * promise in the queue (if any). If no promises are waiting, the mutex
     * becomes available for immediate acquisition.
     *
     * Important: This method should only be called by the task that currently
     * holds the lock. Calling release() without holding the lock may lead to
     * undefined behavior.
     *
     *
     * @throws \RuntimeException If the queue contains an invalid promise type
     */
    public function release(): void
    {
        if ($this->queue->isEmpty()) {
            // No one is waiting, unlock the mutex
            $this->locked = false;
        } else {
            // Give the lock to the next waiting promise in the queue
            $promise = $this->queue->dequeue();

            // Ensure we have a valid Promise instance
            if (! $promise instanceof Promise) {
                throw new \RuntimeException('Invalid promise type in mutex queue');
            }

            // The mutex remains locked, but ownership transfers to the next waiter
            $promise->resolve($this);
        }
    }

    /**
     * Check if the mutex is currently locked.
     *
     * This method is primarily useful for debugging or monitoring purposes.
     * It should not be used for control flow decisions as the lock state
     * can change between checking and acting on the result.
     *
     * @return bool True if the mutex is locked, false otherwise
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Get the number of promises waiting to acquire the lock.
     *
     * This method returns the count of pending acquire() operations that
     * are waiting for the mutex to become available.
     *
     * @return int The number of promises in the waiting queue
     */
    public function getQueueLength(): int
    {
        return $this->queue->count();
    }

    /**
     * Check if there are any promises waiting in the queue.
     *
     * @return bool True if the queue is empty, false if there are waiting promises
     */
    public function isQueueEmpty(): bool
    {
        return $this->queue->isEmpty();
    }
}
