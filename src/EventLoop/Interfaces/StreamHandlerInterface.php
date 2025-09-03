<?php 

namespace Rcalicdan\FiberAsync\EventLoop\Interfaces;

interface StreamHandlerInterface
{
    public function addStreamWatcher($stream, callable $callback, string $type): string;
    public function removeStreamWatcher(string $watcherId): bool;
    public function processStreams(): bool;
    public function hasWatchers(): bool;
    public function clearAllWatchers(): void;
}
