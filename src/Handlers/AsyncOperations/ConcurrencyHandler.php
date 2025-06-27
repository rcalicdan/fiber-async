<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use RuntimeException;
use Throwable;

class ConcurrencyHandler
{
    private AsyncExecutionHandler $executionHandler;

    public function __construct(AsyncExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
    }

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

            $processNext = function () use (&$processNext, &$taskList, &$originalKeys, &$running, &$completed, &$results, &$total, &$taskIndex, $concurrency, $resolve, $reject) {
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $taskList[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $promise = is_callable($task) ? $this->executionHandler->async($task)() : $task;

                        if (!($promise instanceof PromiseInterface)) {
                            throw new RuntimeException("Task must return a Promise or be a callable that returns a Promise");
                        }
                    } catch (Throwable $e) {
                        $running--;
                        $reject($e);
                        return;
                    }

                    $promise
                        ->then(function ($result) use ($originalKey, &$results, &$running, &$completed, $total, $resolve, $processNext) {
                            $results[$originalKey] = $result;
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($results);
                            } else {
                                AsyncEventLoop::getInstance()->nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use (&$running, $reject) {
                            $running--;
                            $reject($error);
                        });
                }
            };

            AsyncEventLoop::getInstance()->nextTick($processNext);
        });
    }
}