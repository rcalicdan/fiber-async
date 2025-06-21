<?php

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

function run(callable|PromiseInterface $asyncOperation): mixed
{
    return Async::run($asyncOperation);
}

function runAll(array $asyncOperations): array
{
    return Async::runAll($asyncOperations);
}

function runConcurrent(array $asyncOperations, int $concurrency = 10): array
{
    return Async::runConcurrent($asyncOperations, $concurrency);
}

function task(callable $asyncFunction): mixed
{
    return Async::task($asyncFunction);
}

function quickFetch(string $url, array $options = []): array
{
    return Async::quickFetch($url, $options);
}

function asyncSleep(float $seconds): void
{
    Async::asyncSleep($seconds);
}

function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
{
    return Async::runWithTimeout($asyncOperation, $timeout);
}

function benchmark(callable|PromiseInterface $asyncOperation): array
{
    return Async::benchmark($asyncOperation);
}
