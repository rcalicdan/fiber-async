<?php

namespace Rcalicdan\FiberAsync\EventLoop\Handlers;

final class TickHandler
{
    private array $tickCallbacks = [];
    private array $deferredCallbacks = [];
    private const BATCH_SIZE = 100;

    public function addNextTick(callable $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    public function addDeferred(callable $callback): void
    {
        $this->deferredCallbacks[] = $callback;
    }

    public function processNextTickCallbacks(): bool
    {
        if (empty($this->tickCallbacks)) {
            return false;
        }

        return $this->processBatch($this->tickCallbacks, 'NextTick');
    }

    public function processDeferredCallbacks(): bool
    {
        if (empty($this->deferredCallbacks)) {
            return false;
        }

        // Process all deferred callbacks in one go
        $callbacks = $this->deferredCallbacks;
        $this->deferredCallbacks = [];

        return $this->executeBatch($callbacks, 'Deferred');
    }

    private function processBatch(array &$callbacks, string $type): bool
    {
        $batchSize = min(self::BATCH_SIZE, count($callbacks));
        $batch = array_splice($callbacks, 0, $batchSize);

        return $this->executeBatch($batch, $type);
    }

    private function executeBatch(array $callbacks, string $type): bool
    {
        $processed = false;

        foreach ($callbacks as $callback) {
            try {
                $callback();
                $processed = true;
            } catch (\Throwable $e) {
                error_log("{$type} callback error: ".$e->getMessage());
            }
        }

        return $processed;
    }

    public function hasTickCallbacks(): bool
    {
        return ! empty($this->tickCallbacks);
    }

    public function hasDeferredCallbacks(): bool
    {
        return ! empty($this->deferredCallbacks);
    }
}
