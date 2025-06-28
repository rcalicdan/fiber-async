<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncOperations;

/**
 * Handles task execution and sleep operations within the async event loop.
 * 
 * This class provides utilities for running async tasks and implementing
 * async sleep functionality.
 * 
 * @package Rcalicdan\FiberAsync\Handlers\LoopOperations
 * @author  Rcalicdan
 */
final readonly class TaskExecutionHandler
{
    /**
     * Async operations instance for task management.
     * 
     * @var AsyncOperations
     */
    private AsyncOperations $asyncOps;

    /**
     * Loop execution handler for running operations.
     * 
     * @var LoopExecutionHandler
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Initialize the task execution handler.
     * 
     * @param AsyncOperations       $asyncOps         Async operations instance
     * @param LoopExecutionHandler  $executionHandler Loop execution handler
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
     * @param callable $asyncFunction The async function to execute
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
     * @param float $seconds Duration to sleep in seconds
     * @return void
     */
    public function asyncSleep(float $seconds): void
    {
        $this->executionHandler->run(function () use ($seconds) {
            $this->asyncOps->await($this->asyncOps->delay($seconds));
        });
    }
}
