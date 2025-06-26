<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Bridges\HttpClientBridge;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Fiber;
use RuntimeException;
use Throwable;
use Exception;

class AsyncOperations
{
    /**
     * Check if code is running inside a Fiber
     */
    public function inFiber(): bool
    {
        return Fiber::getCurrent() !== null;
    }

    /**
     * Creates an async function that returns a Promise
     */
    public function async(callable $asyncFunction): callable
    {
        return function (...$args) use ($asyncFunction) {
            return new AsyncPromise(function ($resolve, $reject) use ($asyncFunction, $args) {
                $fiber = new Fiber(function () use ($asyncFunction, $args, $resolve, $reject) {
                    try {
                        $result = $asyncFunction(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                AsyncEventLoop::getInstance()->addFiber($fiber);
            });
        };
    }

    /**
     * Awaits a Promise and returns its resolved value or throws on rejection
     */
    public function await(PromiseInterface $promise): mixed
    {
        if (!$this->inFiber()) {
            throw new RuntimeException('await() can only be used inside a Fiber context');
        }

        $result = null;
        $error = null;
        $completed = false;

        $promise
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            })
            ->catch(function ($reason) use (&$error, &$completed) {
                $error = $reason;
                $completed = true;
            });

        // Suspend the fiber until the promise completes
        while (!$completed) {
            Fiber::suspend();
        }

        if ($error !== null) {
            throw $error instanceof Throwable ? $error : new Exception((string) $error);
        }

        return $result;
    }

    /**
     * Delays execution for a specified number of seconds
     */
    public function delay(float $seconds): PromiseInterface
    {
        return new AsyncPromise(function ($resolve) use ($seconds) {
            AsyncEventLoop::getInstance()->addTimer($seconds, function () use ($resolve) {
                $resolve(null);
            });
        });
    }

    /**
     * Makes an async HTTP request
     */
    public function fetch(string $url, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($url, $options) {
            AsyncEventLoop::getInstance()->addHttpRequest($url, $options, function ($error, $response, $httpCode) use ($resolve, $reject) {
                if ($error) {
                    $reject(new Exception('HTTP Request failed: ' . $error));
                } else {
                    $resolve([
                        'body' => $response,
                        'status' => $httpCode,
                        'ok' => $httpCode >= 200 && $httpCode < 300,
                    ]);
                }
            });
        });
    }

    /**
     * Resolves when all promises are resolved, rejects if any promise rejects
     */
    public function all(array $promises): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $resolve([]);
                return;
            }

            $results = [];
            $completed = 0;
            $total = count($promises);

            foreach ($promises as $index => $promise) {
                $promise
                    ->then(function ($value) use (&$results, &$completed, $total, $index, $resolve) {
                        $results[$index] = $value;
                        $completed++;
                        if ($completed === $total) {
                            ksort($results); // Maintain order
                            $resolve(array_values($results));
                        }
                    })
                    ->catch(function ($reason) use ($reject) {
                        $reject($reason);
                    });
            }
        });
    }

    /**
     * Resolves when any promise resolves, rejects only if all promises reject
     */
    public function race(array $promises): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $reject(new Exception('No promises provided'));
                return;
            }

            $settled = false;

            foreach ($promises as $promise) {
                $promise
                    ->then(function ($value) use (&$settled, $resolve) {
                        if (!$settled) {
                            $settled = true;
                            $resolve($value);
                        }
                    })
                    ->catch(function ($reason) use (&$settled, $reject) {
                        if (!$settled) {
                            $settled = true;
                            $reject($reason);
                        }
                    });
            }
        });
    }

    /**
     * Creates a resolved promise
     */
    public function resolve(mixed $value): PromiseInterface
    {
        $promise = new AsyncPromise;
        $promise->resolve($value);
        return $promise;
    }

    /**
     * Creates a rejected promise
     */
    public function reject(mixed $reason): PromiseInterface
    {
        $promise = new AsyncPromise;
        $promise->reject($reason);
        return $promise;
    }

    /**
     * Try-catch wrapper for async operations
     */
    public function tryAsync(callable $asyncFunction): callable
    {
        return $this->async(function (...$args) use ($asyncFunction) {
            try {
                return $this->await($asyncFunction(...$args));
            } catch (Throwable $e) {
                throw $e; // Re-throw to be caught by calling code
            }
        });
    }

    /**
     * Make any synchronous function asynchronous
     */
    public function asyncify(callable $syncFunction): callable
    {
        return function (...$args) use ($syncFunction) {
            return new AsyncPromise(function ($resolve, $reject) use ($syncFunction, $args) {
                $fiber = new Fiber(function () use ($syncFunction, $args, $resolve, $reject) {
                    try {
                        $result = $syncFunction(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                AsyncEventLoop::getInstance()->addFiber($fiber);
            });
        };
    }

    /**
     * Guzzle HTTP client bridge
     */
    public function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return HttpClientBridge::getInstance()->guzzle($method, $url, $options);
    }

    /**
     * Laravel HTTP client bridge
     */
    public function http()
    {
        return HttpClientBridge::getInstance()->laravel();
    }

    /**
     * Wrap synchronous operations
     */
    public function wrapSync(callable $syncCall): PromiseInterface
    {
        return HttpClientBridge::getInstance()->wrap($syncCall);
    }

    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($tasks, $concurrency) {
            if (empty($tasks)) {
                $resolve([]);
                return;
            }

            // FIX: Convert tasks to indexed array and preserve original keys
            $taskList = array_values($tasks); // Get sequential numeric indices
            $originalKeys = array_keys($tasks); // Preserve original keys for results

            $results = [];
            $running = 0;
            $completed = 0;
            $total = count($taskList);
            $taskIndex = 0;

            $processNext = function () use (&$processNext, &$taskList, &$originalKeys, &$running, &$completed, &$results, &$total, &$taskIndex, $concurrency, $resolve, $reject) {
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $taskList[$currentIndex]; // FIX: Use taskList with numeric indices
                    $originalKey = $originalKeys[$currentIndex]; // Get the original key
                    $running++;

                    // FIX: Ensure we have a proper promise
                    try {
                        $promise = is_callable($task) ? $this->async($task)() : $task;

                        // FIX: Validate that we got a promise
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
                            $results[$originalKey] = $result; // FIX: Use original key for results
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                // FIX: Maintain original key order
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

            // Start initial batch
            AsyncEventLoop::getInstance()->nextTick($processNext);
        });
    }
}
