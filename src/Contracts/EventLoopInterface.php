<?php

namespace Rcalicdan\FiberAsync\Contracts;

interface EventLoopInterface
{
    public function addTimer(float $delay, callable $callback): string;
    public function addHttpRequest(string $url, array $options, callable $callback): void;
    public function addStreamWatcher($stream, callable $callback): void;
    public function addFiber(\Fiber $fiber): void;
    public function nextTick(callable $callback): void;
    public function run(): void;
    public function stop(): void;
    public function defer(callable $callback): void;
    public function isIdle(): bool;
}