<?php

namespace Rcalicdan\FiberAsync\Promise;

use Rcalicdan\FiberAsync\Promise\Handlers\CallbackHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\ChainHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\ExecutorHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\ResolutionHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\StateHandler;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Implementation of the Promise pattern for asynchronous operations.
 *
 * This class provides a complete Promise/A+ compatible implementation that
 * supports chaining, error handling, and various callback patterns. It manages
 * the lifecycle of asynchronous operations and provides a clean API for
 * handling eventual results or failures.
 *
 * Promises can be in one of three states: pending, resolved (fulfilled), or
 * rejected. Once settled (resolved or rejected), a promise cannot change state.
 */
class Promise implements PromiseInterface
{
    /**
     * @var StateHandler Manages the promise's state (pending, resolved, rejected)
     */
    private StateHandler $stateHandler;

    /**
     * @var CallbackHandler Manages then, catch, and finally callback queues
     */
    private CallbackHandler $callbackHandler;

    /**
     * @var ExecutorHandler Handles the initial executor function execution
     */
    private ExecutorHandler $executorHandler;

    /**
     * @var ChainHandler Manages promise chaining and callback scheduling
     */
    private ChainHandler $chainHandler;

    /**
     * @var ResolutionHandler Handles promise resolution and rejection logic
     */
    private ResolutionHandler $resolutionHandler;

    protected ?CancellablePromise $rootCancellable = null;

    /**
     * Create a new promise with an optional executor function.
     *
     * The executor function receives resolve and reject callbacks that
     * can be used to settle the promise. If no executor is provided,
     * the promise starts in a pending state.
     *
     * @param  callable|null  $executor  Function to execute immediately with resolve/reject callbacks
     */
    public function __construct(?callable $executor = null)
    {
        $this->stateHandler = new StateHandler;
        $this->callbackHandler = new CallbackHandler;
        $this->executorHandler = new ExecutorHandler;
        $this->chainHandler = new ChainHandler;
        $this->resolutionHandler = new ResolutionHandler(
            $this->stateHandler,
            $this->callbackHandler
        );

        $this->executorHandler->executeExecutor(
            $executor,
            fn ($value) => $this->resolve($value),
            fn ($reason) => $this->reject($reason)
        );
    }

    /**
     * Resolve the promise with a value.
     *
     * If the promise is already settled, this operation has no effect.
     * The resolution triggers all registered fulfillment callbacks.
     *
     * @param  mixed  $value  The value to resolve the promise with
     */
    public function resolve(mixed $value): void
    {
        $this->resolutionHandler->handleResolve($value);
    }

    /**
     * Reject the promise with a reason.
     *
     * If the promise is already settled, this operation has no effect.
     * The rejection triggers all registered rejection callbacks.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     */
    public function reject(mixed $reason): void
    {
        $this->resolutionHandler->handleReject($reason);
    }

    /**
     * Attach fulfillment and rejection handlers to the promise.
     *
     * Returns a new promise that will be resolved with the return value
     * of the executed handler. This enables promise chaining.
     *
     * @param  callable|null  $onFulfilled  Handler for when promise is resolved
     * @param  callable|null  $onRejected  Handler for when promise is rejected
     * @return PromiseInterface A new promise for the chained operation
     */
    /**
     * Attach fulfillment and rejection handlers to the promise.
     *
     * Returns a new promise that will be resolved with the return value
     * of the executed handler. This enables promise chaining.
     *
     * @param  callable|null  $onFulfilled  Handler for when promise is resolved
     * @param  callable|null  $onRejected  Handler for when promise is rejected
     * @return PromiseInterface A new promise for the chained operation
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        $origin = $this;

        $newPromise = new self(function ($resolve, $reject) use ($onFulfilled, $onRejected, $origin) {
            $root = $origin instanceof CancellablePromise
                ? $origin
                : ($origin->rootCancellable ?? null);

            $handleResolve = function ($value) use ($onFulfilled, $resolve, $reject, $root) {
                if ($root?->isCancelled()) {
                    return;
                }

                if ($onFulfilled) {
                    try {
                        $result = $onFulfilled($value);
                        if ($result instanceof PromiseInterface) {
                            $result->then($resolve, $reject);
                        } else {
                            $resolve($result);
                        }
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $resolve($value);
                }
            };

            $handleReject = function ($reason) use ($onRejected, $resolve, $reject, $root) {
                if ($root?->isCancelled()) {
                    return;
                }

                if ($onRejected) {
                    try {
                        $result = $onRejected($reason);
                        if ($result instanceof PromiseInterface) {
                            $result->then($resolve, $reject);
                        } else {
                            $resolve($result);
                        }
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $reject($reason);
                }
            };

            if ($this->stateHandler->isResolved()) {
                $this->chainHandler->scheduleHandler(fn () => $handleResolve($this->stateHandler->getValue()));
            } elseif ($this->stateHandler->isRejected()) {
                $this->chainHandler->scheduleHandler(fn () => $handleReject($this->stateHandler->getReason()));
            } else {
                $this->callbackHandler->addThenCallback($handleResolve);
                $this->callbackHandler->addCatchCallback($handleReject);
            }
        });

        if ($this instanceof CancellablePromise) {
            $newPromise->rootCancellable = $this;
        } elseif ($this->rootCancellable) {
            $newPromise->rootCancellable = $this->rootCancellable;
        }

        return $newPromise;
    }

    public function getRootCancellable(): ?CancellablePromise
    {
        return $this->rootCancellable;
    }

    /**
     * Attach a rejection handler to the promise.
     *
     * This is a convenience method equivalent to calling then(null, onRejected).
     * Returns a new promise for chaining.
     *
     * @param  callable  $onRejected  Handler for when promise is rejected
     * @return PromiseInterface A new promise for the chained operation
     */
    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Attach a handler that executes regardless of promise outcome.
     *
     * The finally handler receives no arguments and its return value
     * does not affect the promise chain. It's used for cleanup operations.
     *
     * @param  callable  $onFinally  Handler to execute when promise settles
     * @return PromiseInterface The same promise instance for chaining
     */
    public function finally(callable $onFinally): PromiseInterface
    {
        $this->callbackHandler->addFinallyCallback($onFinally);

        return $this;
    }

    /**
     * Check if the promise has been resolved.
     *
     * @return bool True if the promise is resolved, false otherwise
     */
    public function isResolved(): bool
    {
        return $this->stateHandler->isResolved();
    }

    /**
     * Check if the promise has been rejected.
     *
     * @return bool True if the promise is rejected, false otherwise
     */
    public function isRejected(): bool
    {
        return $this->stateHandler->isRejected();
    }

    /**
     * Check if the promise is still pending.
     *
     * @return bool True if the promise is pending, false if settled
     */
    public function isPending(): bool
    {
        return $this->stateHandler->isPending();
    }

    /**
     * Get the resolved value of the promise.
     *
     * Only returns a meaningful value if the promise is resolved.
     * Check isResolved() before calling this method.
     *
     * @return mixed The resolved value
     */
    public function getValue(): mixed
    {
        return $this->stateHandler->getValue();
    }

    /**
     * Get the rejection reason of the promise.
     *
     * Only returns a meaningful value if the promise is rejected.
     * Check isRejected() before calling this method.
     *
     * @return mixed The rejection reason
     */
    public function getReason(): mixed
    {
        return $this->stateHandler->getReason();
    }
}
