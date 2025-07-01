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
     * @param  AsyncExecutionHandler  $executionHandler  Handler for async execution
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
     * @param  array  $tasks  Array of callable tasks or Promise instances
     * @param  int  $concurrency  Maximum number of tasks to run simultaneously (default: 10)
     * @return PromiseInterface Promise that resolves with an array of all results
     *
     * @throws RuntimeException If a task doesn't return a Promise
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return new AsyncPromise(function ($resolve) use ($tasks, $concurrency) {
            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            $taskKeys = array_keys($tasks);
            $taskCount = count($tasks);
            $results = [];
            $completedCount = 0;
            $runningCount = 0;
            $nextTaskIndex = 0;

            $launchNextTask = function () use (
                &$nextTaskIndex,
                &$runningCount,
                $taskKeys,
                $tasks,
                &$results,
                &$completedCount,
                $taskCount,
                $resolve,
                &$launchNextTask,
                $concurrency
            ) {
                // This is the final resolution condition
                if ($completedCount === $taskCount) {
                    ksort($results);
                    $resolve($results);
                    return;
                }

                while ($runningCount < $concurrency && $nextTaskIndex < $taskCount) {
                    $key = $taskKeys[$nextTaskIndex];
                    $task = $tasks[$key];
                    $nextTaskIndex++;
                    $runningCount++;

                    try {
                        $promise = is_callable($task) ? ($this->executionHandler->async($task))() : $task;
                        if (!($promise instanceof PromiseInterface)) {
                            throw new RuntimeException('Task must return a Promise');
                        }
                    } catch (Throwable $e) {
                        // Task creation itself failed.
                        $results[$key] = $e;
                        $completedCount++;
                        $runningCount--;
                        // Recurse to try and launch another task
                        $launchNextTask();
                        continue;
                    }

                    $promise->then(
                        function ($value) use ($key, &$results, &$completedCount, &$runningCount, $launchNextTask) {
                            $results[$key] = $value;
                            $completedCount++;
                            $runningCount--;
                            $launchNextTask();
                        },
                        // FIX: This now collects errors instead of failing fast.
                        function ($error) use ($key, &$results, &$completedCount, &$runningCount, $launchNextTask) {
                            $results[$key] = $error;
                            $completedCount++;
                            $runningCount--;
                            $launchNextTask();
                        }
                    );
                }
            };

            // Start the process
            $launchNextTask();
        });
    }
}
