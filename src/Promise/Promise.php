<?php

namespace Rcalicdan\FiberAsync\Promise;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Promise\Handlers\AwaitHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\CallbackHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\ChainHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\ExecutorHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\ResolutionHandler;
use Rcalicdan\FiberAsync\Promise\Handlers\StateHandler;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseCollectionInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A Promise/A+ compliant implementation for managing asynchronous operations.
 *
 * This class provides a robust mechanism for handling eventual results or
 * failures from asynchronous tasks. It supports chaining, error handling,
 * and a clear lifecycle (pending, fulfilled, rejected).
 *
 * @template TValue
 *
 * @implements PromiseInterface<TValue>
 */
class Promise implements PromiseCollectionInterface, PromiseInterface
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

    /**
     * @var AwaitHandler
     */
    private AwaitHandler $awaitHandler;

    /**
     * @var CancellablePromiseInterface<mixed>|null
     */
    protected ?CancellablePromiseInterface $rootCancellable = null;

    /**
     * @var AsyncOperations|null Static instance for collection operations
     */
    private static ?AsyncOperations $asyncOps = null;

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
        $this->awaitHandler = new AwaitHandler;
        $this->resolutionHandler = new ResolutionHandler(
            $this->stateHandler,
            $this->callbackHandler
        );

        $this->executorHandler->executeExecutor(
            $executor,
            fn($value) => $this->resolve($value),
            fn($reason) => $this->reject($reason)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function await(bool $resetEventLoop = true): mixed
    {
        return $this->awaitHandler->await($this, $resetEventLoop);
    }

    /**
     * {@inheritdoc}
     */
    public function isSettled(): bool
    {
        // A promise is settled if it is no longer pending.
        return ! $this->stateHandler->isPending();
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
     * {@inheritdoc}
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)|null  $onFulfilled
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)|null  $onRejected
     * @return PromiseInterface<TResult>
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        /** @var Promise<TResult> $newPromise */
        $newPromise = new self(
            /**
             * @param  callable(TResult): void  $resolve
             * @param  callable(mixed): void  $reject
             */
            function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
                $root = $this instanceof CancellablePromiseInterface
                    ? $this
                    : $this->rootCancellable;

                $handleResolve = function ($value) use ($onFulfilled, $resolve, $reject, $root) {
                    if ($root !== null && $root->isCancelled()) {
                        return;
                    }

                    if ($onFulfilled !== null) {
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
                    if ($root !== null && $root->isCancelled()) {
                        return;
                    }

                    if ($onRejected !== null) {
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
                    $this->chainHandler->scheduleHandler(fn() => $handleResolve($this->stateHandler->getValue()));
                } elseif ($this->stateHandler->isRejected()) {
                    $this->chainHandler->scheduleHandler(fn() => $handleReject($this->stateHandler->getReason()));
                } else {
                    $this->callbackHandler->addThenCallback($handleResolve);
                    $this->callbackHandler->addCatchCallback($handleReject);
                }
            }
        );

        if ($this instanceof CancellablePromiseInterface) {
            $newPromise->rootCancellable = $this;
        } elseif ($this->rootCancellable !== null) {
            $newPromise->rootCancellable = $this->rootCancellable;
        }

        return $newPromise;
    }

    /**
     * @return CancellablePromiseInterface<mixed>|null
     */
    public function getRootCancellable(): ?CancellablePromiseInterface
    {
        return $this->rootCancellable;
    }

    /**
     * {@inheritdoc}
     *
     * @template TResult
     *
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)  $onRejected
     * @return PromiseInterface<TResult>
     */
    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<TValue>
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

    /**
     * Get or create the AsyncOperations instance for static methods.
     */
    private static function getAsyncOps(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations;
        }

        return self::$asyncOps;
    }

    /**
     * {@inheritdoc}
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
    }

    /**
     * {@inheritdoc}
     */
    public static function resolved(mixed $value): PromiseInterface
    {
        return self::getAsyncOps()->resolved($value);
    }

    /**
     * {@inheritdoc}
     */
    public static function rejected(mixed $reason): PromiseInterface
    {
        return self::getAsyncOps()->rejected($reason);
    }

    /**
     * {@inheritdoc}
     */
    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->all($promises);
    }

    /**
     * {@inheritdoc}
     */
    public static function allSettled(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->allSettled($promises);
    }

    /**
     * {@inheritdoc}
     */
    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->race($promises);
    }

    /**
     * {@inheritdoc}
     */
    public static function any(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->any($promises);
    }

    /**
     * {@inheritdoc}
     */
    public static function timeout(callable|PromiseInterface|array $promises, float $seconds): PromiseInterface
    {
        return self::getAsyncOps()->timeout($promises, $seconds);
    }

    /**
     * {@inheritdoc}
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOps()->concurrent($tasks, $concurrency);
    }

    /**
     * {@inheritdoc}
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getAsyncOps()->batch($tasks, $batchSize, $concurrency);
    }
}
