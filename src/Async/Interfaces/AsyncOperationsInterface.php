<?php

namespace Rcalicdan\FiberAsync\Async\Interfaces;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

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
     * @return bool True if executing within a fiber, false otherwise
     */
    public function inFiber(): bool;

    /**
     * Creates a resolved promise with the given value.
     *
     * @param  mixed  $value  The value to resolve the promise with
     * @return PromiseInterface A promise resolved with the provided value
     */
    public function resolve(mixed $value): PromiseInterface;

    /**
     * Creates a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception or error message)
     * @return PromiseInterface A promise rejected with the provided reason
     */
    public function reject(mixed $reason): PromiseInterface;

    /**
     * Wraps a synchronous function to make it asynchronous.
     *
     * The returned callable will execute the original function within a fiber
     * and return a promise that resolves with the function's result.
     *
     * @param  callable  $asyncFunction  The function to wrap asynchronously
     * @return callable|PromiseInterface A callable that returns a PromiseInterface
     */
    public function async(callable $asyncFunction): callable|PromiseInterface;

    /**
     * Wraps an async function with error handling.
     *
     * If the async function throws an exception, it will be caught and
     * the returned promise will be rejected with that exception.
     *
     * @param  callable  $asyncFunction  The async function to wrap with error handling
     * @return callable A callable that returns a promise with built-in error handling
     */
    public function tryAsync(callable $asyncFunction): callable;

    /**
     * Suspends execution until the promise is resolved or rejected.
     *
     * This method should only be called within a fiber context.
     * It will yield control back to the event loop until the promise settles.
     *
     * @param  PromiseInterface  $promise  The promise to await
     * @return mixed The resolved value of the promise
     *
     * @throws mixed The rejection reason if the promise is rejected
     */
    public function await(PromiseInterface $promise): mixed;

    /**
     * Creates a promise that resolves after the specified delay.
     *
     * @param  float  $seconds  The delay in seconds (supports fractions for milliseconds)
     * @return PromiseInterface A promise that resolves after the delay
     */
    public function delay(float $seconds): PromiseInterface;

    /**
     * Waits for all promises to resolve or any to reject.
     *
     * Returns a promise that resolves with an array of all resolved values
     * in the same order as the input promises, or rejects with the first rejection.
     *
     * @param  array  $promises  Array of PromiseInterface instances
     * @return PromiseInterface A promise that resolves with an array of results
     */
    public function all(array $promises): PromiseInterface;

    /**
     * Returns a promise that settles with the first promise to settle.
     *
     * The returned promise will resolve or reject with the value/reason
     * of whichever input promise settles first.
     *
     * @param  array  $promises  Array of PromiseInterface instances
     * @return PromiseInterface A promise that settles with the first settled promise
     */
    public function race(array $promises): PromiseInterface;

    /**
     * Executes multiple async tasks with controlled concurrency.
     *
     * Limits the number of simultaneously executing tasks while ensuring
     * all tasks eventually complete.
     *
     * @param  array  $tasks  Array of callable tasks that return promises
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10)
     * @return PromiseInterface A promise that resolves when all tasks complete
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;
}
