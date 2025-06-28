<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

/**
 * Manages the running state of the event loop.
 *
 * This handler provides simple state management for the event loop,
 * allowing it to be started, stopped, and queried for its current state.
 * This is essential for controlling the main event loop execution.
 */
final class StateHandler
{
    /**
     * @var bool Whether the event loop is currently running
     */
    private bool $running = true;

    /**
     * Check if the event loop is currently running.
     *
     * @return bool True if running, false if stopped
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Stop the event loop.
     *
     * This will cause the main event loop to exit on its next iteration.
     * Useful for graceful shutdown or when all work is completed.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Start the event loop.
     *
     * This resets the running state to true, allowing the event loop
     * to continue processing if it was previously stopped.
     */
    public function start(): void
    {
        $this->running = true;
    }
}
