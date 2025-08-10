<?php

namespace Rcalicdan\FiberAsync\Promise\Interfaces;

/**
 * Interface for promise collection operations.
 *
 * This interface defines static methods for working with collections of promises,
 * including operations like waiting for all promises, racing promises, and
 * managing concurrent execution with limits.
 */
interface PromiseCollectionInterface
{
    /**
     * Create a resolved promise with the given value.
     *
     * @template TResolveValue
     *
     * @param  TResolveValue  $value  The value to resolve the promise with
     * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
     */
    public static function resolved(mixed $value): PromiseInterface;

    /**
     * Create a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface<mixed> A promise rejected with the provided reason
     */
    public static function rejected(mixed $reason): PromiseInterface;

    /**
     * Wait for all promises to resolve and return their results.
     *
     * If any promise rejects, the returned promise will reject with
     * the first rejection reason.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises  Array of promises to wait for
     * @return PromiseInterface<array<mixed>> A promise that resolves with an array of results
     */
    public static function all(array $promises): PromiseInterface;

    /**
     * Wait for the first promise to resolve or reject.
     *
     * Returns a promise that settles with the same value/reason as
     * the first promise to settle.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises  Array of promises to race
     * @return PromiseInterface<mixed> A promise that settles with the first result
     */
    public static function race(array $promises): PromiseInterface;

    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises  Array of promises to wait for
     * @return PromiseInterface<mixed> A promise that resolves with the first settled value
     */
    public static function any(array $promises): PromiseInterface;

    /**
     * Create a promise that resolves or rejects with a timeout.
     *
     * @param  callable(): PromiseInterface<mixed>|PromiseInterface<mixed>|array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises
     * @param  float  $seconds  Timeout in seconds
     * @return PromiseInterface<mixed>
     */
    public static function timeout(callable|PromiseInterface|array $promises, float $seconds): PromiseInterface;

    /**
     * Execute multiple tasks with a concurrency limit.
     *
     * Processes tasks in batches to avoid overwhelming the system
     * with too many concurrent operations.
     *
     * @param  array<int|string, callable(): mixed|PromiseInterface<mixed>>  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<mixed>> A promise that resolves with all results
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for
     * controlled concurrency and resource management.
     *
     * @param  array<int|string, callable(): mixed|PromiseInterface<mixed>>  $tasks  Array of tasks (callables or promises) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<mixed>> A promise that resolves with all results
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Reset the static AsyncOperations instance for testing purposes.
     *
     * This method is primarily intended for use in unit tests to ensure
     * a clean state between test runs. It clears the shared AsyncOperations
     * instance, forcing a new one to be created on the next static method call.
     */
    public static function reset(): void;
}
