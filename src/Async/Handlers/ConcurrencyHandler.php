<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

final readonly class ConcurrencyHandler
{
    private AsyncExecutionHandler $executionHandler;

    public function __construct(AsyncExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
    }

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit.
     *
     * IMPORTANT: Tasks must be callables that return promises, NOT pre-created promises.
     * This ensures proper concurrency control by starting tasks only when allowed.
     *
     * @param  array  $tasks  Array of callable tasks (NOT promises!)
     * @param  int  $concurrency  Maximum number of tasks to run simultaneously
     * @return PromiseInterface Promise that resolves with an array of all results
     *
     * @throws InvalidArgumentException If tasks contain non-callables or pre-created promises
     * @throws RuntimeException If a task doesn't return a Promise
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($tasks, $concurrency) {
            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));
                return;
            }

            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            foreach ($tasks as $index => $task) {
                if (!is_callable($task)) {
                    $reject(new InvalidArgumentException(
                        "Task at index '$index' must be a callable. " .
                            "Did you pass a pre-created Promise instead of a callable? " .
                            "Use fn() => yourAsyncFunction() instead of yourAsyncFunction()."
                    ));
                    return;
                }
            }

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
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $taskList[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $promise = $this->executionHandler->async($task)();

                        if (!($promise instanceof PromiseInterface)) {
                            throw new RuntimeException(
                                "Task at index '$originalKey' must return a Promise. " .
                                    "Make sure your callable returns a Promise-based async operation."
                            );
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
                                // Process next task immediately instead of nextTick
                                $processNext();
                            }
                        })
                        ->catch(function ($error) use (&$running, $reject) {
                            $running--;
                            $reject($error);
                        });
                }
            };

            // Start initial batch of tasks
            $processNext();
        });
    }

    /**
     * Execute tasks in sequential batches with concurrency within each batch.
     *
     * IMPORTANT: Tasks must be callables that return promises, NOT pre-created promises.
     *
     * @param  array  $tasks  Array of callable tasks (NOT promises!)
     * @param  int  $batchSize  Number of tasks per batch
     * @param  int  $concurrency  Maximum concurrent tasks within each batch
     * @return PromiseInterface Promise that resolves with an array of all results
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($tasks, $batchSize, $concurrency) {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));
                return;
            }

            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            foreach ($tasks as $index => $task) {
                if (!is_callable($task)) {
                    $reject(new InvalidArgumentException(
                        "Task at index '$index' must be a callable. " .
                            "Did you pass a pre-created Promise instead of a callable? " .
                            "Use fn() => yourAsyncFunction() instead of yourAsyncFunction()."
                    ));
                    return;
                }
            }

            $concurrency = $concurrency ?? $batchSize;
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
                        $processNextBatch();
                    })
                    ->catch($reject);
            };

            $processNextBatch();
        });
    }
}
