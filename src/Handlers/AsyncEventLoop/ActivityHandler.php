<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop;

/**
 * Handles activity tracking for the event loop to determine idle states.
 *
 * This handler tracks the last activity time and provides methods to check
 * if the event loop has been idle for a certain period. This is useful for
 * optimization decisions like when to sleep or perform cleanup operations.
 */
final class ActivityHandler
{
    /** @var int Unix timestamp of the last recorded activity */
    private int $lastActivity = 0;

    /**
     * Initialize the activity handler with current time.
     */
    public function __construct()
    {
        $this->lastActivity = time();
    }

    /**
     * Update the last activity timestamp to the current time.
     *
     * This should be called whenever significant work is performed
     * in the event loop to maintain accurate idle detection.
     */
    public function updateLastActivity(): void
    {
        $this->lastActivity = time();
    }

    /**
     * Check if the event loop has been idle for more than 5 seconds.
     *
     * @return bool True if idle for more than 5 seconds, false otherwise
     */
    public function isIdle(): bool
    {
        return (time() - $this->lastActivity) > 5;
    }

    /**
     * Get the timestamp of the last recorded activity.
     *
     * @return int Unix timestamp of last activity
     */
    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }
}
