<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

/**
 * Handles next-tick and deferred callback processing for the event loop.
 * 
 * This handler manages two types of callbacks:
 * - Next-tick callbacks: Execute immediately on the next event loop iteration
 * - Deferred callbacks: Execute after the current phase of work is completed
 * 
 * These provide different scheduling priorities for asynchronous operations.
 */
final class TickHandler
{
    /** @var array<callable> Callbacks to execute on the next tick */
    private array $tickCallbacks = [];
    
    /** @var array<callable> Callbacks to execute after current work phase */
    private array $deferredCallbacks = [];

    /**
     * Schedule a callback to run on the next event loop tick.
     * 
     * Next-tick callbacks have the highest priority and will execute
     * before any other work in the next loop iteration.
     * 
     * @param callable $callback The callback function to execute
     */
    public function addNextTick(callable $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    /**
     * Schedule a callback to run after the current work phase.
     * 
     * Deferred callbacks run after all immediate work is processed
     * but before the loop sleeps or waits for events.
     * 
     * @param callable $callback The callback function to execute
     */
    public function addDeferred(callable $callback): void
    {
        $this->deferredCallbacks[] = $callback;
    }

    /**
     * Process all pending next-tick callbacks.
     * 
     * Executes all next-tick callbacks in the order they were added.
     * Catches and logs any exceptions to prevent loop termination.
     * 
     * @return bool True if any callbacks were processed, false otherwise
     */
    public function processNextTickCallbacks(): bool
    {
        if (empty($this->tickCallbacks)) {
            return false;
        }

        while (!empty($this->tickCallbacks)) {
            $callback = array_shift($this->tickCallbacks);

            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('NextTick callback error: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Process all pending deferred callbacks.
     * 
     * Takes a snapshot of current deferred callbacks and clears the queue
     * before processing to allow new deferred callbacks to be added during execution.
     * 
     * @return bool True if any callbacks were processed, false otherwise
     */
    public function processDeferredCallbacks(): bool
    {
        if (empty($this->deferredCallbacks)) {
            return false;
        }

        $callbacks = $this->deferredCallbacks;
        $this->deferredCallbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('Deferred callback error: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Check if there are pending next-tick callbacks.
     * 
     * @return bool True if callbacks are pending, false otherwise
     */
    public function hasTickCallbacks(): bool
    {
        return !empty($this->tickCallbacks);
    }

    /**
     * Check if there are pending deferred callbacks.
     * 
     * @return bool True if callbacks are pending, false otherwise
     */
    public function hasDeferredCallbacks(): bool
    {
        return !empty($this->deferredCallbacks);
    }
}