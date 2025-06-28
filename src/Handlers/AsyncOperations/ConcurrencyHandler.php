<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * Handles concurrent execution of multiple tasks with concurrency limiting.
 * 
 * This handler manages the execution of multiple asynchronous tasks while
 * limiting the number of tasks that run simultaneously. This is useful for
 * controlling resource usage and preventing overwhelming external services.
 */
final readonly class ConcurrencyHandler
{
    private AsyncExecutionHandler $executionHandler;

    /**
     * @param AsyncExecutionHandler $executionHandler Handler for async execution
     */
    public function __construct(AsyncExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
    }

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit.
     * 
     * This method runs multiple tasks simultaneously while ensuring that no more
     * than the specified number of tasks run at the same time. Tasks can be either
     * callable functions or existing Promise instances.
     * 
     * @param array $tasks Array of callable tasks or Promise instances
     * @param int $concurrency Maximum number of tasks to run simultaneously (default: 10)
     * @return PromiseInterface Promise that resolves with an array of all results
     * @throws RuntimeException If a task doesn't return a Promise
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($tasks, $concurrency) {
            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            // Convert tasks to indexed array and preserve original keys
            $taskList = array_values($tasks);
            $originalKeys = array_keys($tasks);

            $results = [];
            $running = 0;
            $completed = 0;
            $total = count($taskList);
            $taskIndex = 0;

            $processNext = function () use (
                &$processNext,
                &$taskList,
                &$originalKeys,
                &$running,
                &$completed,
                &$results,
                &$total,
                &$taskIndex,
                $concurrency,
                $resolve,
                $reject
            ) {
                // Start as many tasks as we can up to the concurrency limit
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $taskList[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $promise = is_callable($task)
                            ? $this->executionHandler->async($task)()
                            : $task;

                        if (!($promise instanceof PromiseInterface)) {
                            throw new RuntimeException("Task must return a Promise or be a callable that returns a Promise");
                        }
                    } catch (Throwable $e) {
                        $running--;
                        $reject($e);
                        return;
                    }

                    $promise
                        ->then(function ($result) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            $total,
                            $resolve,
                            $processNext
                        ) {
                            $results[$originalKey] = $result;
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($results);
                            } else {
                                // Schedule next task processing on next tick
                                AsyncEventLoop::getInstance()->nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use (&$running, $reject) {
                            $running--;
                            $reject($error);
                        });
                }
            };

            // Start initial batch of tasks
            AsyncEventLoop::getInstance()->nextTick($processNext);
        });
    }
}