<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncEventLoop\Handler;

class TickHandler
{
    private array $tickCallbacks = [];
    private array $deferredCallbacks = [];

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

        while (!empty($this->tickCallbacks)) {
            $callback = array_shift($this->tickCallbacks);

            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('NextTick callback error: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function processDeferredCallbacks(): bool
    {
        if (empty($this->deferredCallbacks)) {
            return false;
        }

        $callbacks = $this->deferredCallbacks;
        $this->deferredCallbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('Deferred callback error: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function hasTickCallbacks(): bool
    {
        return !empty($this->tickCallbacks);
    }

    public function hasDeferredCallbacks(): bool
    {
        return !empty($this->deferredCallbacks);
    }
}