<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Exception;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\CancellablePromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles operations on collections of Promises.
 *
 * This handler provides utility methods for working with multiple Promises
 * simultaneously, including waiting for all to complete or racing to get
 * the first result. These are essential patterns in async programming.
 */
final readonly class PromiseCollectionHandler
{
    /**
     * Wait for all Promises in a collection to resolve.
     *
     * This method takes an array of Promises and returns a single Promise
     * that resolves when all input Promises have resolved. If any Promise
     * rejects, the returned Promise immediately rejects.
     *
     * @param  array  $promises  Array of Promise instances
     * @return PromiseInterface Promise that resolves with array of all results
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
            $rejected = false; // Add fail-fast flag

            foreach ($promises as $index => $promise) {
                if (!($promise instanceof PromiseInterface)) {
                    $reject(new Exception('All items in array must be Promises'));
                    return;
                }

                $promise->then(
                    function ($value) use (&$results, &$completed, $total, $index, $resolve, &$rejected) {
                        if ($rejected) return;

                        $results[$index] = $value;
                        $completed++;
                        if ($completed === $total) {
                            ksort($results);
                            // FIX: Remove array_values to preserve associative keys.
                            $resolve($results);
                        }
                    },
                    function ($reason) use ($reject, &$rejected) {
                        if ($rejected) return;

                        $rejected = true;
                        $reject($reason);
                    }
                );
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
        return new AsyncPromise(function ($resolve, $reject) use ($promises) {
            if (empty($promises)) {
                $reject(new Exception('No promises provided'));

                return;
            }

            $settled = false;

            foreach ($promises as $index => $promise) {
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
        } elseif ($promise instanceof AsyncPromise) {
            $rootCancellable = $promise->getRootCancellable();
            if ($rootCancellable && ! $rootCancellable->isCancelled()) {
                $rootCancellable->cancel();
            }
        }
    }
}
