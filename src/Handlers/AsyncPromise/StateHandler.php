<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

/**
 * Manages the internal state of Promise instances.
 * 
 * This handler tracks the current state of a Promise (pending, resolved, rejected)
 * and stores the associated value or reason. It ensures that Promises can only
 * be settled once and provides methods to query the current state.
 */
final class StateHandler
{
    /** @var bool Whether the Promise has been resolved */
    private bool $resolved = false;
    
    /** @var bool Whether the Promise has been rejected */
    private bool $rejected = false;
    
    /** @var mixed The resolved value (if resolved) */
    private mixed $value = null;
    
    /** @var mixed The rejection reason (if rejected) */
    private mixed $reason = null;

    /**
     * Check if the Promise has been resolved.
     * 
     * @return bool True if resolved, false otherwise
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Check if the Promise has been rejected.
     * 
     * @return bool True if rejected, false otherwise
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * Check if the Promise is still pending (not settled).
     * 
     * @return bool True if pending, false if resolved or rejected
     */
    public function isPending(): bool
    {
        return !$this->resolved && !$this->rejected;
    }

    /**
     * Get the resolved value.
     * 
     * @return mixed The resolved value (null if not resolved)
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Get the rejection reason.
     * 
     * @return mixed The rejection reason (null if not rejected)
     */
    public function getReason(): mixed
    {
        return $this->reason;
    }

    /**
     * Check if the Promise can be settled (resolved or rejected).
     * 
     * A Promise can only be settled once. After resolution or rejection,
     * further attempts to settle it will be ignored.
     * 
     * @return bool True if the Promise can be settled, false if already settled
     */
    public function canSettle(): bool
    {
        return !$this->resolved && !$this->rejected;
    }

    /**
     * Resolve the Promise with a value.
     * 
     * This method can only be called once per Promise. Subsequent calls
     * will be ignored if the Promise has already been settled.
     * 
     * @param mixed $value The value to resolve with
     */
    public function resolve(mixed $value): void
    {
        if (!$this->canSettle()) {
            return;
        }

        $this->resolved = true;
        $this->value = $value;
    }

    /**
     * Reject the Promise with a reason.
     * 
     * This method can only be called once per Promise. Subsequent calls
     * will be ignored if the Promise has already been settled. The reason
     * is automatically wrapped in an Exception if it's not already a Throwable.
     * 
     * @param mixed $reason The reason to reject with
     */
    public function reject(mixed $reason): void
    {
        if (!$this->canSettle()) {
            return;
        }

        $this->rejected = true;
        $this->reason = $reason instanceof \Throwable
            ? $reason
            : new \Exception((string) $reason);
    }
}