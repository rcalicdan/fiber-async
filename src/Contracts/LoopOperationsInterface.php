<?php

namespace Rcalicdan\FiberAsync\Contracts;

interface LoopOperationsInterface
{
    public function run(callable|PromiseInterface $asyncOperation): mixed;
    public function runAll(array $asyncOperations): array;
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array;
    public function task(callable $asyncFunction): mixed;
    public function asyncSleep(float $seconds): void;
    public function quickFetch(string $url, array $options = []): array;
    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed;
    public function benchmark(callable|PromiseInterface $asyncOperation): array;
    public function formatBenchmark(array $benchmarkResult): string;
}