<?php

namespace Rcalicdan\FiberAsync\Async\Interfaces;

use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Provides core asynchronous operations for fiber-based async programming.
 *
 * This interface defines the essential methods for working with promises, fibers,
 * and asynchronous operations in a fiber-based async environment.
 */
interface AsyncOperationsInterface
{
    /**
     * Checks if the current execution context is within a fiber.
     *
     * @return bool True if executing within a fiber, false otherwise.
     */
    public function inFiber(): bool;

    /**
     * Creates a resolved promise with the given value.
     *
     * @template TValue
     *
     * @param  TValue  $value  The value to resolve the promise with.
     * @return PromiseInterface<TValue> A promise resolved with the provided value.
     */
    public function resolved(mixed $value): PromiseInterface;

    /**
     * Creates a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception or error message).
     * @return PromiseInterface<mixed> A promise rejected with the provided reason.
     */
    public function rejected(mixed $reason): PromiseInterface;

    /**
     * Wraps a synchronous function to make it asynchronous.
     *
     * The returned callable will execute the original function within a fiber
     * and return a promise that resolves with the function's result.
     *
     * @param  callable  $asyncFunction  The function to wrap asynchronously.
     * @return callable(): PromiseInterface<mixed> A callable that returns a PromiseInterface.
     */
    public function async(callable $asyncFunction): callable;

    /**
     * Suspends execution until the promise is resolved or rejected.
     *
     * This method should only be called within a fiber context.
     * It will yield control back to the event loop until the promise settles.
     *
     * @template TValue
     *
     * @param  PromiseInterface<TValue>  $promise  The promise to await.
     * @return TValue The resolved value of the promise.
     *
     * @throws Throwable The rejection reason if the promise is rejected.
     */
    public function await(PromiseInterface $promise): mixed;

    /**
     * Creates a promise that resolves with null after the specified delay.
     *
     * @param  float  $seconds  The delay in seconds (supports fractions for milliseconds).
     * @return CancellablePromiseInterface<null> A promise that resolves with null after the delay.
     */
    public function delay(float $seconds): CancellablePromiseInterface;

    /**
     * Waits for all promises to resolve or any to reject.
     *
     * Returns a promise that resolves with an array of all resolved values
     * in the same order as the input promises, or rejects with the first rejection.
     *
     * @param  array<PromiseInterface<mixed>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<array<mixed>> A promise that resolves with an array of results.
     */
    public function all(array $promises): PromiseInterface;

     /**
     * Wait for all promises to settle (either resolve or reject).
     * 
     * Unlike all(), this method waits for every promise to complete and returns
     * all results, including both successful values and rejection reasons.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: mixed, reason?: mixed}>>
     */
    public function allSettled(array $promises): PromiseInterface;

    /**
     * Returns a promise that settles with the first promise to settle.
     *
     * The returned promise will resolve or reject with the value/reason
     * of whichever input promise settles first.
     *
     * @param  array<PromiseInterface<mixed>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<mixed> A promise that settles with the first settled promise.
     */
    public function race(array $promises): PromiseInterface;

    /**
     * Executes multiple async tasks with controlled concurrency.
     *
     * Limits the number of simultaneously executing tasks while ensuring
     * all tasks eventually complete.
     *
     * @param  array<callable(): PromiseInterface<mixed>>  $tasks  Array of callable tasks that return promises.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<mixed>> A promise that resolves with an array of all results when all tasks complete.
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for
     * controlled concurrency and resource management.
     *
     * @param  array<callable(): PromiseInterface<mixed>>  $tasks  Array of tasks (callables that return promises) to execute.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<mixed>> A promise that resolves with all results.
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;
}
