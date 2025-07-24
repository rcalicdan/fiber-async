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
        return new Promise(function ($resolve, $reject) use ($tasks, $concurrency) {
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
                        })
                    ;
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
 * entire batch to complete before starting the next batch.
 *
 * @param  array  $tasks  Array of callable tasks or Promise instances
 * @param  int  $batchSize  Number of tasks per batch (default: 10)
 * @param  int  $concurrency  Maximum concurrent tasks within each batch (default: same as batch size)
 * @return PromiseInterface Promise that resolves with an array of all results
 */
public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
{
    return new Promise(function ($resolve, $reject) use ($tasks, $batchSize, $concurrency) {
        if (empty($tasks)) {
            $resolve([]);
            return;
        }

        $concurrency = $concurrency ?? $batchSize;

        // Preserve original keys
        $originalKeys = array_keys($tasks);
        $taskValues = array_values($tasks);
        $batches = array_chunk($taskValues, $batchSize, false);
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
}
