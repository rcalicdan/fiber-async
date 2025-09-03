<?php 

namespace Rcalicdan\FiberAsync\EventLoop\Interfaces;

interface EventLoopHandlerInterface
{
    public function run(callable $workCallback): void;
    public function stop(): void;
    public function isRunning(): bool;
}