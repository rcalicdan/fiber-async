<?php

namespace Rcalicdan\FiberAsync\Facades;

use Rcalicdan\FiberAsync\AsyncManager;
use Rcalicdan\FiberAsync\LoopManager;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class Async
{
    protected static ?AsyncManager $asyncManager = null;
    protected static ?LoopManager $loopManager = null;

    protected static function getAsyncManager(): AsyncManager
    {
        if (self::$asyncManager === null) {
            self::$asyncManager = AsyncManager::getInstance();
        }
        return self::$asyncManager;
    }

    protected static function getLoopManager(): LoopManager
    {
        if (self::$loopManager === null) {
            self::$loopManager = LoopManager::getInstance();
        }
        return self::$loopManager;
    }

    public static function inFiber(): bool
    {
        return self::getAsyncManager()->inFiber();
    }

    public static function async(callable $asyncFunction): callable
    {
        return self::getAsyncManager()->async($asyncFunction);
    }

    public static function await(PromiseInterface $promise): mixed
    {
        return self::getAsyncManager()->await($promise);
    }

    public static function delay(float $seconds): PromiseInterface
    {
        return self::getAsyncManager()->delay($seconds);
    }

    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return self::getAsyncManager()->fetch($url, $options);
    }

    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncManager()->all($promises);
    }

    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncManager()->race($promises);
    }

    public static function resolve(mixed $value): PromiseInterface
    {
        return self::getAsyncManager()->resolve($value);
    }

    public static function reject(mixed $reason): PromiseInterface
    {
        return self::getAsyncManager()->reject($reason);
    }

    public static function tryAsync(callable $asyncFunction): callable
    {
        return self::getAsyncManager()->tryAsync($asyncFunction);
    }

    public static function asyncify(callable $syncFunction): callable
    {
        return self::getAsyncManager()->asyncify($syncFunction);
    }

    public static function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return self::getAsyncManager()->guzzle($method, $url, $options);
    }

    public static function http()
    {
        return self::getAsyncManager()->http();
    }

    public static function wrapSync(callable $syncCall): PromiseInterface
    {
        return self::getAsyncManager()->wrapSync($syncCall);
    }

    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncManager()->concurrent($tasks, $concurrency);
    }

    // LoopManager methods
    public static function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return self::getLoopManager()->run($asyncOperation);
    }

    public static function runAll(array $asyncOperations): array
    {
        return self::getLoopManager()->runAll($asyncOperations);
    }

    public static function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return self::getLoopManager()->runConcurrent($asyncOperations, $concurrency);
    }

    public static function task(callable $asyncFunction): mixed
    {
        return self::getLoopManager()->task($asyncFunction);
    }

    public static function quickFetch(string $url, array $options = []): array
    {
        return self::getLoopManager()->quickFetch($url, $options);
    }

    public static function asyncSleep(float $seconds): void
    {
        self::getLoopManager()->asyncSleep($seconds);
    }

    public static function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return self::getLoopManager()->runWithTimeout($asyncOperation, $timeout);
    }

    public static function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        return self::getLoopManager()->benchmark($asyncOperation);
    }
}
