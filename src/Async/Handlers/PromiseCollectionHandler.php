<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Exception;
use InvalidArgumentException;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
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

    /**
     * Run multiple Promises in parallel and return a Promise that resolves when all Promises are settled.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises
     * @return PromiseInterface<array<int|string, mixed>>
     */
    public function all(array $promises): PromiseInterface
    {
        /** @var Promise<array<int|string, mixed>> */
        return new Promise(function (callable $resolve, callable $reject) use ($promises): void {
            if ($promises === []) {
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
                    ->then(function ($value) use (&$results, &$completed, $total, $key, $resolve, $hasStringKeys): void {
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
                    ->catch(function ($reason) use ($reject): void {
                        $reject($reason);
                    })
                ;
            }
        });
    }

    /**
     * Wait for all promises to settle (either resolve or reject).
     *
     * Unlike all(), this method waits for every promise to complete and returns
     * all results, including both successful values and rejection reasons.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: mixed, reason?: mixed}>>
     */
    public function allSettled(array $promises): PromiseInterface
    {
        /** @var Promise<array<int|string, array{status: 'fulfilled'|'rejected', value?: mixed, reason?: mixed}>> */
        return new Promise(function (callable $resolve, callable $reject) use ($promises): void {
            if ($promises === []) {
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
                    // If task creation fails, treat as rejected settlement
                    $results[$key] = [
                        'status' => 'rejected',
                        'reason' => $e,
                    ];
                    $completed++;

                    if ($completed === $total) {
                        if ($hasStringKeys) {
                            $resolve($results);
                        } else {
                            ksort($results);
                            $resolve(array_values($results));
                        }
                    }

                    continue;
                }

                $promise
                    ->then(function ($value) use (&$results, &$completed, $total, $key, $resolve, $hasStringKeys): void {
                        $results[$key] = [
                            'status' => 'fulfilled',
                            'value' => $value,
                        ];
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
                    ->catch(function ($reason) use (&$results, &$completed, $total, $key, $resolve, $hasStringKeys): void {
                        $results[$key] = [
                            'status' => 'rejected',
                            'reason' => $reason,
                        ];
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
                ;
            }
        });
    }

    /**
     * Race multiple Promises and return the first to settle.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises
     * @return PromiseInterface<mixed>
     */
    public function race(array $promises): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];
        $settled = false;

        $cancellablePromise = new CancellablePromise(function (callable $resolve, callable $reject) use ($promises, &$promiseInstances, &$settled): void {
            if ($promises === []) {
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
                    ->then(function ($value) use ($resolve, &$settled, &$promiseInstances, $index): void {
                        if ($settled) {
                            return;
                        }

                        $this->handleRaceSettlement($settled, $promiseInstances, $index);
                        $resolve($value);
                    })
                    ->catch(function ($reason) use ($reject, &$settled, &$promiseInstances, $index): void {
                        if ($settled) {
                            return;
                        }

                        $this->handleRaceSettlement($settled, $promiseInstances, $index);
                        $reject($reason);
                    })
                ;
            }
        });

        $cancellablePromise->setCancelHandler(function () use (&$promiseInstances, &$settled): void {
            $settled = true;
            foreach ($promiseInstances as $promise) {
                $this->cancelPromiseIfPossible($promise);
            }
        });

        return $cancellablePromise;
    }

    /**
     * @param  callable(): PromiseInterface<mixed>|PromiseInterface<mixed>|array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $operations
     * @return PromiseInterface<mixed>
     */
    public function timeout(
        callable|PromiseInterface|array $operations,
        float $seconds
    ): CancellablePromiseInterface {
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

        /** @var array<int|string, callable(): CancellablePromiseInterface<mixed>|CancellablePromiseInterface<mixed>> $racePromises */
        $racePromises = [...$promises, $timeoutPromise];

        return $this->race($racePromises);
    }

    /**
     * Wait for any Promise in a collection to resolve.
     *
     * @param  array<int|string, callable(): PromiseInterface<mixed>|PromiseInterface<mixed>>  $promises
     * @return PromiseInterface<mixed>
     */
    public function any(array $promises): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];
        $settled = false;

        $cancellablePromise = new CancellablePromise(
            function (callable $resolve, callable $reject) use ($promises, &$promiseInstances, &$settled): void {
                if ($promises === []) {
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
                            function ($value) use ($resolve, &$settled, &$promiseInstances, $index): void {
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
                            ): void {
                                if ($settled) {
                                    return;
                                }

                                $rejections[$index] = $reason;
                                $rejectedCount++;

                                if ($rejectedCount === $total) {
                                    $settled = true;
                                    $rejectionsJson = json_encode($rejections);
                                    $reject(
                                        new Exception(
                                            'All promises rejected',
                                            0,
                                            new Exception($rejectionsJson !== false ? $rejectionsJson : 'Failed to encode rejections')
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
            function () use (&$promiseInstances, &$settled): void {
                $settled = true;
                foreach ($promiseInstances as $promise) {
                    $this->cancelPromiseIfPossible($promise);
                }
            }
        );

        return $cancellablePromise;
    }

    /**
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     */
    private function handleAnySettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseIfPossible($promise);
        }
    }

    /**
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     */
    private function handleRaceSettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseIfPossible($promise);
        }
    }

    /**
     * @param  PromiseInterface<mixed>  $promise
     */
    private function cancelPromiseIfPossible(PromiseInterface $promise): void
    {
        if ($promise instanceof CancellablePromise && ! $promise->isCancelled()) {
            $promise->cancel();
        } elseif ($promise instanceof Promise) {
            $rootCancellable = $promise->getRootCancellable();
            if ($rootCancellable !== null && ! $rootCancellable->isCancelled()) {
                $rootCancellable->cancel();
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $array
     */
    private function hasStringKeys(array $array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }
}
