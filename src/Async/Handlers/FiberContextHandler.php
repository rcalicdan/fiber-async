<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Fiber;
use RuntimeException;

/**
 * Handles validation and detection of Fiber execution contexts.
 *
 * This handler provides utilities to check if code is running within a Fiber
 * and validate that operations requiring Fiber context are called appropriately.
 * This is essential for async operations that depend on Fiber suspension/resumption.
 */
final readonly class FiberContextHandler
{
    /**
     * Check if the current code is executing within a Fiber.
     *
     * @return bool True if running in a Fiber, false otherwise
     */
    public function inFiber(): bool
    {
        return Fiber::getCurrent() !== null;
    }

    /**
     * Validate that the current execution context is within a Fiber.
     *
     * Throws an exception if not running within a Fiber context.
     * This is used by operations that require Fiber suspension capabilities.
     *
     * @throws RuntimeException If not executing within a Fiber context
     */
    public function validateFiberContext(): void
    {
        if (! $this->inFiber()) {
            throw new RuntimeException('Operation can only be used inside a Fiber context');
        }
    }
}
