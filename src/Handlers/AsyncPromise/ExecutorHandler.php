<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

/**
 * Handles execution of Promise executor functions.
 *
 * This handler manages the execution of the executor function passed to
 * Promise constructors. It ensures proper error handling and provides
 * the resolve/reject functions to the executor.
 */
final readonly class ExecutorHandler
{
    /**
     * Execute a Promise executor function with proper error handling.
     *
     * The executor function is called with resolve and reject functions.
     * If the executor throws an exception, the Promise is automatically
     * rejected with that exception.
     *
     * @param  callable|null  $executor  The executor function to run
     * @param  callable  $resolve  Function to resolve the Promise
     * @param  callable  $reject  Function to reject the Promise
     */
    public function executeExecutor(?callable $executor, callable $resolve, callable $reject): void
    {
        if (! $executor) {
            return;
        }

        try {
            $executor($resolve, $reject);
        } catch (\Throwable $e) {
            $reject($e);
        }
    }
}
