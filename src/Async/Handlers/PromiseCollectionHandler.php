<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Exception;
use InvalidArgumentException;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use RuntimeException;
use Throwable;

/**
 * Handles operations on collections of Promises.
 */
final readonly class PromiseCollectionHandler
{
    private AsyncExecutionHandler $executionHandler;
    private TimerHandler $timerHandler;

    public function __construct()
    {
        $this->executionHandler = new AsyncExecutionHandler;
        $this->timerHandler = new TimerHandler;
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

                    if (! ($promise instanceof PromiseInterface)) {
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
     */
    /**
     * Race multiple Promises and return the first to settle.
     */
    public function race(array $promises): PromiseInterface
    {
        $promiseInstances = [];
        $settled = false;

        $cancellablePromise = new CancellablePromise(function ($resolve, $reject) use ($promises, &$promiseInstances, &$settled) {
            if (empty($promises)) {
                $reject(new Exception('No promises provided'));

                return;
            }

            foreach ($promises as $index => $item) {
                try {
                    if (is_callable($item)) {
                        $promise = $item();

                        if (! ($promise instanceof PromiseInterface)) {
                            $promise = $this->executionHandler->async($item)();
                        }
                    } else {
                        $promise = $item;
                    }

                    if (! ($promise instanceof PromiseInterface)) {
                        throw new RuntimeException('Item must return a Promise or be a callable that returns a Promise');
                    }

                    $promiseInstances[$index] = $promise;
                } catch (Throwable $e) {
                    foreach ($promiseInstances as $p) {
                        $this->cancelPromiseIfPossible($p);
                    }
                    $reject($e);

                    return;
                }

                $promise
                    ->then(function ($value) use ($resolve, &$settled, &$promiseInstances, $index) {
                        if ($settled) {
                            return;
                        }

                        $this->handleRaceSettlement($settled, $promiseInstances, $index);
                        $resolve($value);
                    })
                    ->catch(function ($reason) use ($reject, &$settled, &$promiseInstances, $index) {
                        if ($settled) {
                            return;
                        }

                        $this->handleRaceSettlement($settled, $promiseInstances, $index);
                        $reject($reason);
                    })
                ;
            }
        });

        $cancellablePromise->setCancelHandler(function () use (&$promiseInstances, &$settled) {
            $settled = true;
            foreach ($promiseInstances as $promise) {
                $this->cancelPromiseIfPossible($promise);
            }
        });

        return $cancellablePromise;
    }

    
    public function timeout(
        callable|PromiseInterface|array $operations,
        float $seconds
    ): PromiseInterface {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than zero');
        }

        $items = is_array($operations) ? $operations : [$operations];
        $promises = array_map(
            fn ($item) => is_callable($item)
                ? $this->executionHandler->async($item)()
                : $item,
            $items
        );

        $timeoutPromise = $this->timerHandler
            ->delay($seconds)
            ->then(fn () => throw new Exception("Operation timed out after {$seconds} seconds"))
        ;

        return $this->race([...$promises, $timeoutPromise]);
    }

    /**
     * Wait for any Promise in a collection to resolve.
     */
    public function any(array $promises): PromiseInterface
    {
        $promiseInstances = [];
        $settled = false;

        $cancellablePromise = new CancellablePromise(
            function ($resolve, $reject) use ($promises, &$promiseInstances, &$settled) {
                if (empty($promises)) {
                    $reject(new Exception('No promises provided'));

                    return;
                }

                $rejections = [];
                $rejectedCount = 0;
                $total = count($promises);

                foreach ($promises as $index => $item) {
                    try {
                        if (is_callable($item)) {
                            $promise = $item();

                            if (! ($promise instanceof PromiseInterface)) {
                                $promise = $this->executionHandler->async($item)();
                            }
                        } else {
                            $promise = $item;
                        }

                        if (! ($promise instanceof PromiseInterface)) {
                            throw new RuntimeException(
                                'Item must return a Promise or be a callable that returns a Promise'
                            );
                        }

                        $promiseInstances[$index] = $promise;
                    } catch (Throwable $e) {
                        foreach ($promiseInstances as $p) {
                            $this->cancelPromiseIfPossible($p);
                        }
                        $reject($e);

                        return;
                    }

                    $promise
                        ->then(
                            function ($value) use ($resolve, &$settled, &$promiseInstances, $index) {
                                if ($settled) {
                                    return;
                                }

                                $this->handleAnySettlement($settled, $promiseInstances, $index);
                                $resolve($value);
                            }
                        )
                        ->catch(
                            function ($reason) use (
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

                                if ($rejectedCount === $total) {
                                    $settled = true;
                                    $reject(
                                        new Exception(
                                            'All promises rejected',
                                            0,
                                            new Exception(json_encode($rejections))
                                        )
                                    );
                                }
                            }
                        )
                    ;
                }
            }
        );

        $cancellablePromise->setCancelHandler(
            function () use (&$promiseInstances, &$settled) {
                $settled = true;
                foreach ($promiseInstances as $promise) {
                    $this->cancelPromiseIfPossible($promise);
                }
            }
        );

        return $cancellablePromise;
    }

    private function handleAnySettlement(bool &$settled, array &$promiseInstances, int $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseIfPossible($promise);
        }
    }

    private function handleRaceSettlement(bool &$settled, array &$promiseInstances, int $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseIfPossible($promise);
        }
    }

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

    private function hasStringKeys(array $array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }
}
