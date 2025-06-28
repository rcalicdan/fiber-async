<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Exception;

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
     * @param array $promises Array of Promise instances
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
     * Race multiple Promises and return the first to settle.
     * 
     * This method returns a Promise that settles (resolves or rejects)
     * as soon as the first Promise in the collection settles. This is
     * useful for implementing timeouts or getting the fastest response.
     * 
     * @param array $promises Array of Promise instances
     * @return PromiseInterface Promise that settles with the first result
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
}
