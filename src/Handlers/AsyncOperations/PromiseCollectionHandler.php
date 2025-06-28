<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Exception;

final readonly class PromiseCollectionHandler
{
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