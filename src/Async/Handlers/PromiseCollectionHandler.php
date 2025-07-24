<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Exception;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use RuntimeException;
use Throwable;

/**
 * Handles operations on collections of Promises.
 *
 * This handler provides utility methods for working with multiple Promises
 * simultaneously, including waiting for all to complete or racing to get
 * the first result. These are essential patterns in async programming.
 */
final readonly class PromiseCollectionHandler
{
    private AsyncExecutionHandler $executionHandler;

    public function __construct()
    {
        $this->executionHandler = new AsyncExecutionHandler();
    }

    public function all(array $promises): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $resolve([]);
                return;
            }

            $results = [];
            $completed = 0;
            $total = count($promises);
            $hasStringKeys = $this->hasStringKeys($promises);

            foreach ($promises as $key => $item) {
                try {
                    $promise = is_callable($item)
                        ? $this->executionHandler->async($item)()
                        : $item;

                    if (!($promise instanceof PromiseInterface)) {
                        throw new RuntimeException('Item must return a Promise or be a callable that returns a Promise');
                    }
                } catch (Throwable $e) {
                    $reject($e);
                    return;
                }

                $promise
                    ->then(function ($value) use (&$results, &$completed, $total, $key, $resolve, $hasStringKeys) {
                        $results[$key] = $value;
                        $completed++;
                        if ($completed === $total) {
                            if ($hasStringKeys) {
                                $resolve($results);
                            } else {
                                ksort($results);
                                $resolve(array_values($results));
                            }
                        }
                    })
                    ->catch(function ($reason) use ($reject) {
                        $reject($reason);
                    })
                ;
            }
        });
    }

    /**
     * Race multiple Promises and return the first to settle.
     *
     * This method returns a Promise that settles (resolves or rejects)
     * as soon as the first Promise in the collection settles. This is
     * useful for implementing timeouts or getting the fastest response.
     *
     * @param  array  $promises  Array of Promise instances
     * @return PromiseInterface Promise that settles with the first result
     *
     * @throws Exception If no promises are provided
     */
    public function race(array $promises): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $reject(new Exception('No promises provided'));
                return;
            }

            $settled = false;

            foreach ($promises as $index => $item) {
                try {
                    $promise = is_callable($item)
                        ? $this->executionHandler->async($item)()
                        : $item;

                    if (!($promise instanceof PromiseInterface)) {
                        throw new RuntimeException('Item must return a Promise or be a callable that returns a Promise');
                    }
                } catch (Throwable $e) {
                    $reject($e);
                    return;
                }

                $promise
                    ->then(function ($value) use ($resolve, &$settled, $promises, $index) {
                        if ($settled) {
                            return;
                        }

                        $this->handleRaceSettlement($settled, $promises, $index);
                        $resolve($value);
                    })
                    ->catch(function ($reason) use ($reject, &$settled, $promises, $index) {
                        if ($settled) {
                            return;
                        }

                        $this->handleRaceSettlement($settled, $promises, $index);
                        $reject($reason);
                    })
                ;
            }
        });
    }

    /**
     * Wait for any Promise in a collection to resolve.
     *
     * This method returns a Promise that resolves as soon as any of the input
     * Promises resolves. Unlike race(), this ignores rejections and only fails
     * if ALL promises reject. Useful for trying multiple fallback options.
     *
     * @param  array  $promises  Array of Promise instances
     * @return PromiseInterface Promise that resolves with the first successful result
     *
     * @throws Exception If no promises are provided or all promises reject
     */
    public function any(array $promises): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $reject(new Exception('No promises provided'));
                return;
            }

            $settled = false;
            $rejections = [];
            $rejectedCount = 0;
            $total = count($promises);

            foreach ($promises as $index => $item) {
                try {
                    $promise = is_callable($item)
                        ? $this->executionHandler->async($item)()
                        : $item;

                    if (!($promise instanceof PromiseInterface)) {
                        throw new RuntimeException('Item must return a Promise or be a callable that returns a Promise');
                    }
                } catch (Throwable $e) {
                    $reject($e);
                    return;
                }

                $promise
                    ->then(function ($value) use ($resolve, &$settled, $promises, $index) {
                        if ($settled) {
                            return;
                        }

                        $this->handleAnySettlement($settled, $promises, $index);
                        $resolve($value);
                    })
                    ->catch(function ($reason) use (
                        &$rejections,
                        &$rejectedCount,
                        &$settled,
                        $total,
                        $index,
                        $reject
                    ) {
                        if ($settled) {
                            return;
                        }

                        $rejections[$index] = $reason;
                        $rejectedCount++;

                        // If all promises have rejected, reject with aggregate error
                        if ($rejectedCount === $total) {
                            $settled = true;
                            $reject(new Exception(
                                'All promises rejected',
                                0,
                                new Exception(json_encode($rejections))
                            ));
                        }
                    });
            }
        });
    }

    /**
     * Handle the settlement of a promise in an any operation.
     *
     * @param  bool  $settled  Reference to the settled flag
     * @param  array  $promises  Array of all promises
     * @param  int  $winnerIndex  Index of the winning promise
     */
    private function handleAnySettlement(bool &$settled, array $promises, int $winnerIndex): void
    {
        $settled = true;

        // Cancel all other promises that didn't win
        foreach ($promises as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }

            $this->cancelPromiseIfPossible($promise);
        }
    }

    /**
     * Handle the settlement of a promise in a race operation.
     *
     * This method marks the race as settled and cancels all other promises
     * that didn't win the race.
     *
     * @param  bool  $settled  Reference to the settled flag
     * @param  array  $promises  Array of all promises in the race
     * @param  int  $winnerIndex  Index of the winning promise
     */
    private function handleRaceSettlement(bool &$settled, array $promises, int $winnerIndex): void
    {
        $settled = true;

        // Cancel all other promises that didn't win
        foreach ($promises as $index => $promise) {
            if ($index === $winnerIndex) {
                continue; // Don't cancel the winning promise
            }

            $this->cancelPromiseIfPossible($promise);
        }
    }

    /**
     * Cancel a promise if it supports cancellation.
     *
     * @param  PromiseInterface  $promise  The promise to cancel
     */
    private function cancelPromiseIfPossible(PromiseInterface $promise): void
    {
        if ($promise instanceof CancellablePromise && ! $promise->isCancelled()) {
            $promise->cancel();
        } elseif ($promise instanceof Promise) {
            $rootCancellable = $promise->getRootCancellable();
            if ($rootCancellable && ! $rootCancellable->isCancelled()) {
                $rootCancellable->cancel();
            }
        }
    }

    /**
     * Check if an array has string keys (associative array).
     *
     * @param  array  $array  The array to check
     * @return bool True if the array has string keys
     */
    private function hasStringKeys(array $array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }
}
