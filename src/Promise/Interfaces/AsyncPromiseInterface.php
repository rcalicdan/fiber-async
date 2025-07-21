<?php

namespace Rcalicdan\FiberAsync\Promise\Interfaces;

/**
 * Extended promise interface with additional async-specific functionality.
 *
 * This interface extends the base PromiseInterface with constructor support
 * and enhanced state inspection methods for async promise implementations.
 */
interface AsyncPromiseInterface extends PromiseInterface
{
    /**
     * Creates a new async promise with an optional executor function.
     *
     * The executor function receives resolve and reject callbacks that can be
     * called to settle the promise.
     *
     * @param  callable|null  $executor  Optional executor function with signature:
     *                                   function(callable $resolve, callable $reject): void
     */
    public function __construct(?callable $executor = null);

    /**
     * Checks if the promise has been resolved with a value.
     *
     * @return bool True if the promise is resolved, false otherwise
     */
    public function isResolved(): bool;

    /**
     * Checks if the promise has been rejected with a reason.
     *
     * @return bool True if the promise is rejected, false otherwise
     */
    public function isRejected(): bool;

    /**
     * Checks if the promise is still pending (neither resolved nor rejected).
     *
     * @return bool True if the promise is pending, false otherwise
     */
    public function isPending(): bool;

    /**
     * Gets the resolved value of the promise.
     *
     * @return mixed The resolved value
     *
     * @throws \LogicException If the promise is not resolved
     */
    public function getValue(): mixed;

    /**
     * Gets the rejection reason of the promise.
     *
     * @return mixed The rejection reason
     *
     * @throws \LogicException If the promise is not rejected
     */
    public function getReason(): mixed;

    /**
     * Attaches callbacks for promise resolution and/or rejection.
     *
     * @param  callable|null  $onFulfilled  Callback for successful resolution
     * @param  callable|null  $onRejected  Callback for rejection handling
     * @return PromiseInterface A new promise for chaining
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;

    /**
     * Attaches a callback for promise rejection only.
     *
     * @param  callable  $onRejected  Callback to handle rejection
     * @return PromiseInterface A new promise for chaining
     */
    public function catch(callable $onRejected): PromiseInterface;

    /**
     * Attaches a callback that runs regardless of promise outcome.
     *
     * The finally callback receives no arguments and its return value
     * does not affect the promise chain.
     *
     * @param  callable  $onFinally  Callback to run after settlement
     * @return PromiseInterface A new promise for chaining
     */
    public function finally(callable $onFinally): PromiseInterface;
}
