<?php

namespace Rcalicdan\FiberAsync\Contracts;

/**
 * Core promise interface for asynchronous operations.
 *
 * Represents a value that may not be available yet but will be resolved
 * or rejected in the future. Provides methods for handling success and
 * failure cases and chaining operations.
 */
interface PromiseInterface
{
    /**
     * Resolves the promise with a successful value.
     *
     * Once resolved, the promise cannot be resolved or rejected again.
     * All attached fulfillment handlers will be called with the value.
     *
     * @param  mixed  $value  The value to resolve the promise with
     */
    public function resolve(mixed $value): void;

    /**
     * Rejects the promise with a failure reason.
     *
     * Once rejected, the promise cannot be resolved or rejected again.
     * All attached rejection handlers will be called with the reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception or error message)
     */
    public function reject(mixed $reason): void;

    /**
     * Attaches handlers for promise fulfillment and/or rejection.
     *
     * Returns a new promise that will be resolved or rejected based on
     * the return value of the executed handler.
     *
     * @param  callable|null  $onFulfilled  Handler for successful resolution with signature:
     *                                      function(mixed $value): mixed
     * @param  callable|null  $onRejected  Handler for rejection with signature:
     *                                     function(mixed $reason): mixed
     * @return PromiseInterface A new promise for method chaining
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;

    /**
     * Attaches a handler for promise rejection only.
     *
     * Equivalent to calling then(null, $onRejected).
     *
     * @param  callable  $onRejected  Handler for rejection with signature:
     *                                function(mixed $reason): mixed
     * @return PromiseInterface A new promise for method chaining
     */
    public function catch(callable $onRejected): PromiseInterface;

    /**
     * Attaches a handler that executes regardless of promise outcome.
     *
     * The finally handler receives no arguments and its return value
     * does not affect the promise chain unless it throws an exception.
     *
     * @param  callable  $onFinally  Handler to execute after settlement with signature:
     *                               function(): mixed
     * @return PromiseInterface A new promise for method chaining
     */
    public function finally(callable $onFinally): PromiseInterface;

    /**
     * Checks if the promise has been resolved with a value.
     *
     * @return bool True if resolved, false otherwise
     */
    public function isResolved(): bool;

    /**
     * Checks if the promise has been rejected with a reason.
     *
     * @return bool True if rejected, false otherwise
     */
    public function isRejected(): bool;

    /**
     * Checks if the promise is still pending (neither resolved nor rejected).
     *
     * @return bool True if pending, false otherwise
     */
    public function isPending(): bool;

    /**
     * Gets the resolved value of the promise.
     *
     * This method should only be called after confirming the promise
     * is resolved using isResolved().
     *
     * @return mixed The resolved value
     *
     * @throws \LogicException If called on a non-resolved promise
     */
    public function getValue(): mixed;

    /**
     * Gets the rejection reason of the promise.
     *
     * This method should only be called after confirming the promise
     * is rejected using isRejected().
     *
     * @return mixed The rejection reason
     *
     * @throws \LogicException If called on a non-rejected promise
     */
    public function getReason(): mixed;
}
