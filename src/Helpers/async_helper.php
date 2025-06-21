<?php

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

function in_fiber(): bool
{
    return Async::inFiber();
}

function async(callable $asyncFunction): callable
{
    return Async::async($asyncFunction);
}

function await(PromiseInterface $promise): mixed
{
    return Async::await($promise);
}

function delay(float $seconds): PromiseInterface
{
    return Async::delay($seconds);
}

function fetch(string $url, array $options = []): PromiseInterface
{
    return Async::fetch($url, $options);
}

function all(array $promises): PromiseInterface
{
    return Async::all($promises);
}

function race(array $promises): PromiseInterface
{
    return Async::race($promises);
}

function resolve(mixed $value): PromiseInterface
{
    return Async::resolve($value);
}

function reject(mixed $reason): PromiseInterface
{
    return Async::reject($reason);
}

function tryAsync(callable $asyncFunction): callable
{
    return Async::tryAsync($asyncFunction);
}

function asyncify(callable $syncFunction): callable
{
    return Async::asyncify($syncFunction);
}

function guzzle(string $method, string $url, array $options = []): PromiseInterface
{
    return Async::guzzle($method, $url, $options);
}

function http()
{
    return Async::http();
}

function wrapSync(callable $syncCall): PromiseInterface
{
    return Async::wrapSync($syncCall);
}

function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
{
    return Async::concurrent($tasks, $concurrency);
}