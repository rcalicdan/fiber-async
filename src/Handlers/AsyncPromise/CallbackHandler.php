<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

class CallbackHandler
{
    private array $thenCallbacks = [];
    private array $catchCallbacks = [];
    private array $finallyCallbacks = [];

    public function addThenCallback(callable $callback): void
    {
        $this->thenCallbacks[] = $callback;
    }

    public function addCatchCallback(callable $callback): void
    {
        $this->catchCallbacks[] = $callback;
    }

    public function addFinallyCallback(callable $callback): void
    {
        $this->finallyCallbacks[] = $callback;
    }

    public function executeThenCallbacks(mixed $value): void
    {
        foreach ($this->thenCallbacks as $callback) {
            try {
                $callback($value);
            } catch (\Throwable $e) {
                error_log('Promise then callback error: ' . $e->getMessage());
            }
        }
    }

    public function executeCatchCallbacks(mixed $reason): void
    {
        foreach ($this->catchCallbacks as $callback) {
            try {
                $callback($reason);
            } catch (\Throwable $e) {
                error_log('Promise catch callback error: ' . $e->getMessage());
            }
        }
    }

    public function executeFinallyCallbacks(): void
    {
        foreach ($this->finallyCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('Promise finally callback error: ' . $e->getMessage());
            }
        }
    }
}