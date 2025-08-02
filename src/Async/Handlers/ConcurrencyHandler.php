<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
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
    private AwaitHandler $awaitHandler;

    /**
     * @param  AsyncExecutionHandler  $executionHandler  Handler for async execution
     */
    public function __construct(AsyncExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
        $this->awaitHandler = new AwaitHandler(new FiberContextHandler);
    }

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit.
     *
     * This method runs multiple tasks simultaneously while ensuring that no more
     * than the specified number of tasks run at the same time. Tasks can be either
     * callable functions or existing Promise instances. Promise instances will be
     * automatically wrapped to ensure proper concurrency control.
     *
     * @param  array  $tasks  Array of callable tasks or Promise instances
     * @param  int  $concurrency  Maximum number of tasks to run simultaneously (default: 10)
     * @return PromiseInterface Promise that resolves with an array of all results
     *
     * @throws RuntimeException If a task doesn't return a Promise
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($tasks, $concurrency) {
            if ($concurrency <= 0) {
                $reject(new \InvalidArgumentException('Concurrency limit must be greater than 0'));
                return;
            }

            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            // Convert tasks to indexed array and preserve original keys
            $taskList = array_values($tasks);
            $originalKeys = array_keys($tasks);

            // Process tasks to ensure proper async wrapping
            $processedTasks = [];
            foreach ($taskList as $index => $task) {
                $processedTasks[$index] = $this->wrapTaskForConcurrency($task);
            }

            $results = [];
            $running = 0;
            $completed = 0;
            $total = count($processedTasks);
            $taskIndex = 0;

            $processNext = function () use (
                &$processNext,
                &$processedTasks,
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
                    $task = $processedTasks[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $promise = $this->executionHandler->async($task)();

                        if (! ($promise instanceof PromiseInterface)) {
                            throw new RuntimeException('Task must return a Promise or be a callable that returns a Promise');
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
                                EventLoop::getInstance()->nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use (&$running, $reject) {
                            $running--;
                            $reject($error);
                        });
                }
            };

            // Start initial batch of tasks
            EventLoop::getInstance()->nextTick($processNext);
        });
    }

    /**
     * Execute tasks in sequential batches with concurrency within each batch.
     *
     * This method processes tasks in batches sequentially, where each batch
     * runs tasks concurrently up to the specified limit, but waits for the
     * entire batch to complete before starting the next batch. Promise instances
     * will be automatically wrapped to ensure proper concurrency control.
     *
     * @param  array  $tasks  Array of callable tasks or Promise instances
     * @param  int  $batchSize  Number of tasks per batch (default: 10)
     * @param  int  $concurrency  Maximum concurrent tasks within each batch (default: same as batch size)
     * @return PromiseInterface Promise that resolves with an array of all results
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($tasks, $batchSize, $concurrency) {
            if ($batchSize <= 0) {
                $reject(new \InvalidArgumentException('Batch size must be greater than 0'));
                return;
            }

            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            $concurrency = $concurrency ?? $batchSize;

            // Preserve original keys and wrap tasks
            $originalKeys = array_keys($tasks);
            $taskValues = array_values($tasks);

            // Process tasks to ensure proper async wrapping
            $processedTasks = [];
            foreach ($taskValues as $index => $task) {
                $processedTasks[$index] = $this->wrapTaskForConcurrency($task);
            }

            $batches = array_chunk($processedTasks, $batchSize, false);
            $keyBatches = array_chunk($originalKeys, $batchSize, false);

            $allResults = [];
            $batchIndex = 0;
            $totalBatches = count($batches);

            $processNextBatch = function () use (
                &$processNextBatch,
                &$batches,
                &$keyBatches,
                &$allResults,
                &$batchIndex,
                $totalBatches,
                $concurrency,
                $resolve,
                $reject
            ) {
                if ($batchIndex >= $totalBatches) {
                    $resolve($allResults);
                    return;
                }

                $currentBatch = $batches[$batchIndex];
                $currentKeys = $keyBatches[$batchIndex];

                $batchTasks = array_combine($currentKeys, $currentBatch);

                $this->concurrent($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (
                        &$allResults,
                        &$batchIndex,
                        $processNextBatch
                    ) {
                        $allResults = array_merge($allResults, $batchResults);
                        $batchIndex++;
                        EventLoop::getInstance()->nextTick($processNextBatch);
                    })
                    ->catch($reject);
            };

            EventLoop::getInstance()->nextTick($processNextBatch);
        });
    }

    /**
     * Wrap a task to ensure proper concurrency control.
     *
     * This method ensures all tasks use the await pattern for proper fiber-based concurrency:
     * - All callables are wrapped to ensure their results are awaited
     * - Promise instances are wrapped with await
     * - Other types are wrapped in a callable
     *
     * @param mixed $task The task to wrap
     * @return callable A callable that properly defers execution
     */
    private function wrapTaskForConcurrency(mixed $task): callable
    {
        if (is_callable($task)) {
            return function () use ($task) {
                $result = $task();
                if ($result instanceof PromiseInterface) {
                    return $this->awaitHandler->await($result);
                }
                return $result;
            };
        }

        if ($task instanceof PromiseInterface) {
            return function () use ($task) {
                return $this->awaitHandler->await($task);
            };
        }

        return function () use ($task) {
            return $task;
        };
    }
}
