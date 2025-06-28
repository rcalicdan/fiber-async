<?php

namespace Rcalicdan\FiberAsync\Facades;

use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\LoopOperations;

final class Async
{
    private static ?AsyncOperations $asyncOps = null;
    private static ?LoopOperations $loopOps = null;

    protected static function getAsyncOperations(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations;
        }

        return self::$asyncOps;
    }

    protected static function getLoopOperations(): LoopOperations
    {
        if (self::$loopOps === null) {
            self::$loopOps = new LoopOperations(self::getAsyncOperations());
        }

        return self::$loopOps;
    }

    /**
     * Reset all cached instances - useful for testing
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
        self::$loopOps = null;
    }

    /**
     * Check if code is running inside a Fiber
     */
    public static function inFiber(): bool
    {
        return self::getAsyncOperations()->inFiber();
    }

    /**
     * Creates an async function that returns a Promise
     */
    public static function async(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->async($asyncFunction);
    }

    /**
     * Awaits a Promise and returns its resolved value or throws on rejection
     */
    public static function await(PromiseInterface $promise): mixed
    {
        return self::getAsyncOperations()->await($promise);
    }

    /**
     * Delays execution for a specified number of seconds
     */
    public static function delay(float $seconds): PromiseInterface
    {
        return self::getAsyncOperations()->delay($seconds);
    }

    /**
     * Makes an async HTTP request
     */
    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return self::getAsyncOperations()->fetch($url, $options);
    }

    /**
     * Resolves when all promises are resolved, rejects if any promise rejects
     */
    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->all($promises);
    }

    /**
     * Resolves when any promise resolves, rejects only if all promises reject
     */
    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->race($promises);
    }

    /**
     * Creates a resolved promise
     */
    public static function resolve(mixed $value): PromiseInterface
    {
        return self::getAsyncOperations()->resolve($value);
    }

    /**
     * Creates a rejected promise
     */
    public static function reject(mixed $reason): PromiseInterface
    {
        return self::getAsyncOperations()->reject($reason);
    }

    /**
     * Try-catch wrapper for async operations
     */
    public static function tryAsync(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->tryAsync($asyncFunction);
    }

    /**
     * Make any synchronous function asynchronous
     */
    public static function asyncify(callable $syncFunction): callable
    {
        return self::getAsyncOperations()->asyncify($syncFunction);
    }

    /**
     * Guzzle HTTP client bridge
     */
    public static function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return self::getAsyncOperations()->guzzle($method, $url, $options);
    }

    /**
     * Laravel HTTP client bridge
     */
    public static function http()
    {
        return self::getAsyncOperations()->http();
    }

    /**
     * Wrap synchronous operations
     */
    public static function wrapSync(callable $syncCall): PromiseInterface
    {
        return self::getAsyncOperations()->wrapSync($syncCall);
    }

    /**
     * Run multiple async operations concurrently with concurrency limit
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOperations()->concurrent($tasks, $concurrency);
    }

    /**
     * Run async operations with automatic event loop management
     */
    public static function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return self::getLoopOperations()->run($asyncOperation);
    }

    /**
     * Run multiple async operations concurrently
     */
    public static function runAll(array $asyncOperations): array
    {
        return self::getLoopOperations()->runAll($asyncOperations);
    }

    /**
     * Run async operations with concurrency limit
     */
    public static function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return self::getLoopOperations()->runConcurrent($asyncOperations, $concurrency);
    }

    /**
     * Create and run a simple async task
     */
    public static function task(callable $asyncFunction): mixed
    {
        return self::getLoopOperations()->task($asyncFunction);
    }

    /**
     * Quick HTTP fetch with automatic loop management
     */
    public static function quickFetch(string $url, array $options = []): array
    {
        return self::getLoopOperations()->quickFetch($url, $options);
    }

    /**
     * Async delay with automatic loop management
     */
    public static function asyncSleep(float $seconds): void
    {
        self::getLoopOperations()->asyncSleep($seconds);
    }

    /**
     * Run with timeout
     */
    public static function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return self::getLoopOperations()->runWithTimeout($asyncOperation, $timeout);
    }

    /**
     * Run and return both result and execution time
     */
    public static function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        return self::getLoopOperations()->benchmark($asyncOperation);
    }
}
