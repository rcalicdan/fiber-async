<?php

namespace Rcalicdan\FiberAsync\Loop\Handlers;

use Rcalicdan\FiberAsync\Async\AsyncOperations;

/**
 * Handles task execution and sleep operations within the async event loop.
 *
 * This class provides utilities for running async tasks and implementing
 * async sleep functionality.
 */
final readonly class TaskExecutionHandler
{
    /**
     * Async operations instance for task management.
     */
    private AsyncOperations $asyncOps;

    /**
     * Loop execution handler for running operations.
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Initialize the task execution handler.
     *
     * @param  AsyncOperations  $asyncOps  Async operations instance
     * @param  LoopExecutionHandler  $executionHandler  Loop execution handler
     */
    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    /**
     * Execute an async task and return its result.
     *
     * Runs the provided async function within the event loop and
     * returns the result synchronously.
     *
     * @param  callable  $asyncFunction  The async function to execute
     * @return mixed The result of the async function
     */
    public function task(callable $asyncFunction): mixed
    {
        return $this->executionHandler->run($this->asyncOps->async($asyncFunction)());
    }

    /**
     * Perform an async sleep operation.
     *
     * Suspends execution for the specified duration without blocking
     * the event loop, allowing other operations to continue.
     *
     * @param  float  $seconds  Duration to sleep in seconds
     */
    public function asyncSleep(float $seconds): void
    {
        $this->asyncOps->await($this->asyncOps->delay($seconds));
    }
}
