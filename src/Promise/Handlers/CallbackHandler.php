<?php

namespace Rcalicdan\FiberAsync\Promise\Handlers;

/**
 * Manages callback registration and execution for Promise instances.
 *
 * This handler maintains collections of callbacks for different Promise states
 * (then, catch, finally) and provides methods to execute them when appropriate.
 * It handles error isolation to prevent callback failures from affecting other callbacks.
 */
final class CallbackHandler
{
    /**
     * @var array<callable> Callbacks to execute when Promise resolves
     */
    private array $thenCallbacks = [];

    /**
     * @var array<callable> Callbacks to execute when Promise rejects
     */
    private array $catchCallbacks = [];

    /**
     * @var array<callable> Callbacks to execute when Promise settles (resolve or reject)
     *  */
    private array $finallyCallbacks = [];

    /**
     * Register a callback to execute when the Promise resolves.
     *
     * @param  callable  $callback  Function to call with the resolved value
     */
    public function addThenCallback(callable $callback): void
    {
        $this->thenCallbacks[] = $callback;
    }

    /**
     * Register a callback to execute when the Promise rejects.
     *
     * @param  callable  $callback  Function to call with the rejection reason
     */
    public function addCatchCallback(callable $callback): void
    {
        $this->catchCallbacks[] = $callback;
    }

    /**
     * Register a callback to execute when the Promise settles (resolves or rejects).
     *
     * @param  callable  $callback  Function to call regardless of Promise outcome
     */
    public function addFinallyCallback(callable $callback): void
    {
        $this->finallyCallbacks[] = $callback;
    }

    /**
     * Execute all registered then callbacks with the resolved value.
     *
     * Each callback is executed in isolation - if one throws an exception,
     * it won't prevent other callbacks from running. Errors are logged.
     *
     * @param  mixed  $value  The resolved value to pass to callbacks
     */
    public function executeThenCallbacks(mixed $value): void
    {
        foreach ($this->thenCallbacks as $callback) {
            try {
                $callback($value);
            } catch (\Throwable $e) {
                error_log('Promise then callback error: '.$e->getMessage());
            }
        }
    }

    /**
     * Execute all registered catch callbacks with the rejection reason.
     *
     * Each callback is executed in isolation - if one throws an exception,
     * it won't prevent other callbacks from running. Errors are logged.
     *
     * @param  mixed  $reason  The rejection reason to pass to callbacks
     */
    public function executeCatchCallbacks(mixed $reason): void
    {
        foreach ($this->catchCallbacks as $callback) {
            try {
                $callback($reason);
            } catch (\Throwable $e) {
                error_log('Promise catch callback error: '.$e->getMessage());
            }
        }
    }

    /**
     * Execute all registered finally callbacks.
     *
     * Finally callbacks don't receive any parameters and are called regardless
     * of whether the Promise resolved or rejected. Each callback is executed
     * in isolation with error logging.
     */
    public function executeFinallyCallbacks(): void
    {
        foreach ($this->finallyCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('Promise finally callback error: '.$e->getMessage());
            }
        }
    }
}
