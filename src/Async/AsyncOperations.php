<?php

namespace Rcalicdan\FiberAsync\Async;

use Rcalicdan\FiberAsync\Async\Handlers\AsyncExecutionHandler;
use Rcalicdan\FiberAsync\Async\Handlers\AwaitHandler;
use Rcalicdan\FiberAsync\Async\Handlers\ConcurrencyHandler;
use Rcalicdan\FiberAsync\Async\Handlers\FiberContextHandler;
use Rcalicdan\FiberAsync\Async\Handlers\PromiseCollectionHandler;
use Rcalicdan\FiberAsync\Async\Handlers\PromiseHandler;
use Rcalicdan\FiberAsync\Async\Handlers\TimerHandler;
use Rcalicdan\FiberAsync\Async\Interfaces\AsyncOperationsInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * High-level interface for asynchronous operations and utilities.
 *
 * This class provides a convenient API for working with asynchronous operations
 * built on top of PHP Fibers. It includes utilities for creating promises,
 * handling HTTP requests, managing timers, and coordinating concurrent operations.
 *
 * The class acts as a facade over various specialized handlers, providing a
 * unified interface for common async patterns and operations.
 */
class AsyncOperations implements AsyncOperationsInterface
{
    /**
     * @var FiberContextHandler Handles fiber context detection and management
     */
    private FiberContextHandler $contextHandler;

    /**
     * @var PromiseHandler Creates and manages basic promise operations
     */
    private PromiseHandler $promiseHandler;

    /**
     * @var AsyncExecutionHandler Handles conversion of sync functions to async
     */
    private AsyncExecutionHandler $executionHandler;

    /**
     * @var AwaitHandler Manages promise awaiting within fiber contexts
     */
    private AwaitHandler $awaitHandler;

    /**
     * @var TimerHandler Manages timer-based asynchronous operations
     */
    private TimerHandler $timerHandler;

    /**
     * @var PromiseCollectionHandler Manages collections of promises (all, race)
     */
    private PromiseCollectionHandler $collectionHandler;

    /**
     * @var ConcurrencyHandler Manages concurrent execution with limits
     */
    private ConcurrencyHandler $concurrencyHandler;

    /**
     * Initialize the async operations system with all required handlers.
     *
     * Sets up all specialized handlers with proper dependency injection
     * to provide a complete asynchronous operations environment.
     */
    public function __construct()
    {
        $this->contextHandler = new FiberContextHandler;
        $this->promiseHandler = new PromiseHandler;
        $this->executionHandler = new AsyncExecutionHandler;
        $this->awaitHandler = new AwaitHandler($this->contextHandler);
        $this->timerHandler = new TimerHandler;
        $this->collectionHandler = new PromiseCollectionHandler;
        $this->concurrencyHandler = new ConcurrencyHandler($this->executionHandler);
    }

    /**
     * Check if the current execution context is within a fiber.
     *
     * This is useful for determining if async operations can be performed
     * or if they need to be wrapped in a fiber context.
     *
     * @return bool True if executing within a fiber, false otherwise
     */
    public function inFiber(): bool
    {
        return $this->contextHandler->inFiber();
    }

    /**
     * Create a resolved promise with the given value.
     *
     * @param  mixed  $value  The value to resolve the promise with
     * @return PromiseInterface A promise resolved with the provided value
     */
    public function resolve(mixed $value): PromiseInterface
    {
        return $this->promiseHandler->resolve($value);
    }

    /**
     * Create a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface A promise rejected with the provided reason
     */
    public function reject(mixed $reason): PromiseInterface
    {
        return $this->promiseHandler->reject($reason);
    }

    /**
     * Convert a regular function into an async function.
     *
     * The returned function will execute the original function within
     * a fiber context, allowing it to use async operations.
     *
     * @param  callable  $asyncFunction  The function to convert to async
     * @return callable An async version of the provided function
     */
    public function async(callable $asyncFunction): callable
    {
        return $this->executionHandler->async($asyncFunction);
    }

    /**
     * Convert a synchronous function to work in async contexts.
     *
     * This wraps a sync function so it can be used alongside async
     * operations without blocking the event loop.
     *
     * @param  callable  $syncFunction  The synchronous function to wrap
     * @return callable An async-compatible version of the function
     */
    public function asyncify(callable $syncFunction): callable
    {
        return $this->executionHandler->asyncify($syncFunction);
    }

    /**
     * Create a safe async function with error handling.
     *
     * The returned function will catch exceptions and convert them
     * to rejected promises, preventing uncaught exceptions.
     *
     * @param  callable  $asyncFunction  The async function to make safe
     * @return callable A safe version of the async function
     */
    public function tryAsync(callable $asyncFunction): callable
    {
        return $this->executionHandler->tryAsync($asyncFunction, $this->contextHandler, $this->awaitHandler);
    }

    /**
     * Await a promise and return its resolved value.
     *
     * This function suspends the current fiber until the promise
     * resolves or rejects. Must be called from within a fiber context.
     *
     * @param  PromiseInterface  $promise  The promise to await
     * @return mixed The resolved value of the promise
     *
     * @throws \Exception If the promise is rejected
     */
    public function await(PromiseInterface $promise): mixed
    {
        return $this->awaitHandler->await($promise);
    }

    /**
     * Create a promise that resolves after a specified delay.
     *
     * @param  float  $seconds  Number of seconds to delay
     * @return PromiseInterface A promise that resolves after the delay
     */
    public function delay(float $seconds): PromiseInterface
    {
        return $this->timerHandler->delay($seconds);
    }

    /**
     * Wait for all promises to resolve and return their results.
     *
     * If any promise rejects, the returned promise will reject with
     * the first rejection reason.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with an array of results
     */
    public function all(array $promises): PromiseInterface
    {
        return $this->collectionHandler->all($promises);
    }

    /**
     * Wait for the first promise to resolve or reject.
     *
     * Returns a promise that settles with the same value/reason as
     * the first promise to settle.
     *
     * @param  array  $promises  Array of promises to race
     * @return PromiseInterface A promise that settles with the first result
     */
    public function race(array $promises): PromiseInterface
    {
        return $this->collectionHandler->race($promises);
    }

    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     *
     * @param  array  $promises  Array of promises to wait for
     * @return PromiseInterface A promise that resolves with the first settled value
     */
    public function any(array $promises): PromiseInterface
    {
        return $this->collectionHandler->any($promises);
    }

    public function timeout(callable|PromiseInterface|array $promises, float $seconds): PromiseInterface
    {
        return $this->collectionHandler->timeout($promises, $seconds);
    }

    /**
     * Execute multiple tasks with a concurrency limit.
     *
     * Processes tasks in batches to avoid overwhelming the system
     * with too many concurrent operations.
     *
     * @param  array  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface A promise that resolves with all results
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return $this->concurrencyHandler->concurrent($tasks, $concurrency);
    }

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for
     * controlled concurrency and resource management.
     *
     * @param  array  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface A promise that resolves with all results
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return $this->concurrencyHandler->batch($tasks, $batchSize, $concurrency);
    }
}
