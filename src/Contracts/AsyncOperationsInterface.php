<?php

namespace Rcalicdan\FiberAsync\Contracts;

interface AsyncOperationsInterface
{
    public function inFiber(): bool;
    public function resolve(mixed $value): PromiseInterface;
    public function reject(mixed $reason): PromiseInterface;
    public function async(callable $asyncFunction): callable;
    public function asyncify(callable $syncFunction): callable;
    public function tryAsync(callable $asyncFunction): callable;
    public function await(PromiseInterface $promise): mixed;
    public function delay(float $seconds): PromiseInterface;
    public function fetch(string $url, array $options = []): PromiseInterface;
    public function guzzle(string $method, string $url, array $options = []): PromiseInterface;
    public function http();
    public function wrapSync(callable $syncCall): PromiseInterface;
    public function all(array $promises): PromiseInterface;
    public function race(array $promises): PromiseInterface;
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;
}
