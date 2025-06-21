<?php

namespace Rcalicdan\FiberAsync\Facades;

use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\LoopOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class Async
{
    private static ?AsyncOperations $asyncOps = null;
    private static ?LoopOperations $loopOps = null;

    protected static function getAsyncOperations(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations();
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

    public static function reset(): void
    {
        self::$asyncOps = null;
        self::$loopOps = null;
    }

    public static function inFiber(): bool
    {
        return self::getAsyncOperations()->inFiber();
    }

    public static function async(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->async($asyncFunction);
    }

    public static function await(PromiseInterface $promise): mixed
    {
        return self::getAsyncOperations()->await($promise);
    }

    public static function delay(float $seconds): PromiseInterface
    {
        return self::getAsyncOperations()->delay($seconds);
    }

    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return self::getAsyncOperations()->fetch($url, $options);
    }

    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->all($promises);
    }

    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncOperations()->race($promises);
    }

    public static function resolve(mixed $value): PromiseInterface
    {
        return self::getAsyncOperations()->resolve($value);
    }

    public static function reject(mixed $reason): PromiseInterface
    {
        return self::getAsyncOperations()->reject($reason);
    }

    public static function tryAsync(callable $asyncFunction): callable
    {
        return self::getAsyncOperations()->tryAsync($asyncFunction);
    }

    public static function asyncify(callable $syncFunction): callable
    {
        return self::getAsyncOperations()->asyncify($syncFunction);
    }

    public static function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return self::getAsyncOperations()->guzzle($method, $url, $options);
    }

    public static function http()
    {
        return self::getAsyncOperations()->http();
    }

    public static function wrapSync(callable $syncCall): PromiseInterface
    {
        return self::getAsyncOperations()->wrapSync($syncCall);
    }

    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOperations()->concurrent($tasks, $concurrency);
    }

    public static function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return self::getLoopOperations()->run($asyncOperation);
    }

    public static function runAll(array $asyncOperations): array
    {
        return self::getLoopOperations()->runAll($asyncOperations);
    }

    public static function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return self::getLoopOperations()->runConcurrent($asyncOperations, $concurrency);
    }

    public static function task(callable $asyncFunction): mixed
    {
        return self::getLoopOperations()->task($asyncFunction);
    }

    public static function quickFetch(string $url, array $options = []): array
    {
        return self::getLoopOperations()->quickFetch($url, $options);
    }

    public static function asyncSleep(float $seconds): void
    {
        self::getLoopOperations()->asyncSleep($seconds);
    }

    public static function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return self::getLoopOperations()->runWithTimeout($asyncOperation, $timeout);
    }

    public static function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        return self::getLoopOperations()->benchmark($asyncOperation);
    }

    public static function runInBackground(callable $blockingOperation, array $args = []): PromiseInterface
    {
        return self::getAsyncOperations()->runInBackground($blockingOperation, $args);
    }

    public static function runConcurrentlyInBackground(array $tasks, int $maxConcurrency = 4): PromiseInterface
    {
        return self::getAsyncOperations()->runConcurrentlyInBackground($tasks, $maxConcurrency);
    }
}
