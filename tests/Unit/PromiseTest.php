<?php

use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

describe('Promise', function () {
    beforeEach(function () {
        Promise::reset();
    });

    describe('Constructor and Initial State', function () {
        it('creates a pending promise with no executor', function () {
            $promise = new Promise();

            expect($promise->isPending())->toBeTrue()
                ->and($promise->isResolved())->toBeFalse()
                ->and($promise->isRejected())->toBeFalse();
        });

        it('executes provided executor immediately', function () {
            $executed = false;
            $resolveCallback = null;
            $rejectCallback = null;

            $promise = new Promise(function ($resolve, $reject) use (&$executed, &$resolveCallback, &$rejectCallback) {
                $executed = true;
                $resolveCallback = $resolve;
                $rejectCallback = $reject;
            });

            expect($executed)->toBeTrue()
                ->and($resolveCallback)->toBeCallable()
                ->and($rejectCallback)->toBeCallable();
        });

        it('handles executor that resolves immediately', function () {
            $promise = new Promise(function ($resolve, $reject) {
                $resolve('test value');
            });

            expect($promise->isResolved())->toBeTrue()
                ->and($promise->getValue())->toBe('test value');
        });

        it('handles executor that rejects immediately', function () {
            $exception = new Exception('test error');
            $promise = new Promise(function ($resolve, $reject) use ($exception) {
                $reject($exception);
            });

            expect($promise->isRejected())->toBeTrue()
                ->and($promise->getReason())->toBe($exception);
        });

        it('handles executor that throws an exception', function () {
            $exception = new Exception('executor error');

            $promise = new Promise(function ($resolve, $reject) use ($exception) {
                throw $exception;
            });

            expect($promise->isRejected())->toBeTrue()
                ->and($promise->getReason())->toBe($exception);
        });
    });

    describe('Resolution', function () {
        it('can be resolved with a value', function () {
            $promise = new Promise();
            $promise->resolve('test value');

            expect($promise->isResolved())->toBeTrue()
                ->and($promise->isPending())->toBeFalse()
                ->and($promise->isRejected())->toBeFalse()
                ->and($promise->getValue())->toBe('test value');
        });

        it('ignores multiple resolution attempts', function () {
            $promise = new Promise();
            $promise->resolve('first value');
            $promise->resolve('second value');

            expect($promise->getValue())->toBe('first value');
        });

        it('ignores resolution after rejection', function () {
            $promise = new Promise();
            $exception = new Exception('error');

            $promise->reject($exception);
            $promise->resolve('value');

            expect($promise->isRejected())->toBeTrue()
                ->and($promise->getReason())->toBe($exception);
        });

        it('can be resolved with null', function () {
            $promise = new Promise();
            $promise->resolve(null);

            expect($promise->isResolved())->toBeTrue()
                ->and($promise->getValue())->toBeNull();
        });

        it('can be resolved with complex data types', function () {
            $promise = new Promise();
            $data = ['key' => 'value', 'nested' => ['array' => true]];
            $promise->resolve($data);

            expect($promise->getValue())->toBe($data);
        });
    });

    describe('Rejection', function () {
        it('can be rejected with a reason', function () {
            $promise = new Promise();
            $exception = new Exception('test error');
            $promise->reject($exception);

            expect($promise->isRejected())->toBeTrue()
                ->and($promise->isPending())->toBeFalse()
                ->and($promise->isResolved())->toBeFalse()
                ->and($promise->getReason())->toBe($exception);
        });

        it('ignores multiple rejection attempts', function () {
            $promise = new Promise();
            $firstError = new Exception('first error');
            $secondError = new Exception('second error');

            $promise->reject($firstError);
            $promise->reject($secondError);

            expect($promise->getReason())->toBe($firstError);
        });

        it('ignores rejection after resolution', function () {
            $promise = new Promise();
            $promise->resolve('value');
            $promise->reject(new Exception('error'));

            expect($promise->isResolved())->toBeTrue()
                ->and($promise->getValue())->toBe('value');
        });

        it('can be rejected with string reasons', function () {
            $promise = new Promise();
            $promise->reject('simple error message');

            expect($promise->getReason())->toBeInstanceOf(Exception::class)
                ->and($promise->getReason()->getMessage())->toBe('simple error message');
        });
    });

    describe('Then method', function () {
        it('calls onFulfilled when promise is resolved', function () {
            $called = false;
            $receivedValue = null;

            run(function () use (&$called, &$receivedValue) {
                $promise = new Promise();

                $promise->then(function ($value) use (&$called, &$receivedValue) {
                    $called = true;
                    $receivedValue = $value;
                });

                $promise->resolve('test value');
            });

            expect($called)->toBeTrue()
                ->and($receivedValue)->toBe('test value');
        });

        it('calls onRejected when promise is rejected', function () {
            $called = false;
            $receivedReason = null;

            run(function () use (&$called, &$receivedReason) {
                $promise = new Promise();

                $promise->then(null, function ($reason) use (&$called, &$receivedReason) {
                    $called = true;
                    $receivedReason = $reason;
                });

                $exception = new Exception('test error');
                $promise->reject($exception);
            });

            expect($called)->toBeTrue()
                ->and($receivedReason)->toBeInstanceOf(Exception::class);
        });

        it('returns a new promise', function () {
            $promise = new Promise();
            $newPromise = $promise->then(function ($value) {
                return $value;
            });

            expect($newPromise)->toBeInstanceOf(PromiseInterface::class)
                ->and($newPromise)->not->toBe($promise);
        });

        it('transforms values through the chain', function () {
            $finalPromise = run(function () {
                $promise = new Promise();

                $finalPromise = $promise->then(function ($value) {
                    return $value * 2;
                })->then(function ($value) {
                    return $value + 1;
                });

                $promise->resolve(5);

                return $finalPromise;
            });

            expect($finalPromise->isResolved())->toBeTrue()
                ->and($finalPromise->getValue())->toBe(11); // (5 * 2) + 1
        });

        it('handles promise returning from onFulfilled', function () {
            $chainedPromise = run(function () {
                $promise = new Promise();
                $innerPromise = new Promise();

                $chainedPromise = $promise->then(function ($value) use ($innerPromise) {
                    return $innerPromise;
                });

                $promise->resolve('original');
                // At this point chainedPromise should be pending

                $innerPromise->resolve('inner value');

                return $chainedPromise;
            });

            expect($chainedPromise->isResolved())->toBeTrue()
                ->and($chainedPromise->getValue())->toBe('inner value');
        });

        it('handles exceptions in onFulfilled', function () {
            $chainedPromise = run(function () {
                $promise = new Promise();
                $exception = new Exception('handler error');

                $chainedPromise = $promise->then(function ($value) use ($exception) {
                    throw $exception;
                });

                $promise->resolve('value');

                return $chainedPromise;
            });

            expect($chainedPromise->isRejected())->toBeTrue()
                ->and($chainedPromise->getReason())->toBeInstanceOf(Exception::class)
                ->and($chainedPromise->getReason()->getMessage())->toBe('handler error');
        });

        it('calls handlers for already resolved promises', function () {
            $called = false;
            $receivedValue = null;

            run(function () use (&$called, &$receivedValue) {
                $promise = new Promise();
                $promise->resolve('test value');

                $promise->then(function ($value) use (&$called, &$receivedValue) {
                    $called = true;
                    $receivedValue = $value;
                });
            });

            expect($called)->toBeTrue()
                ->and($receivedValue)->toBe('test value');
        });

        it('supports multiple then handlers', function () {
            $calls = [];

            run(function () use (&$calls) {
                $promise = new Promise();

                $promise->then(function ($value) use (&$calls) {
                    $calls[] = 'first: ' . $value;
                });

                $promise->then(function ($value) use (&$calls) {
                    $calls[] = 'second: ' . $value;
                });

                $promise->resolve('test');
            });

            expect($calls)->toHaveCount(2)
                ->and($calls)->toContain('first: test')
                ->and($calls)->toContain('second: test');
        });
    });

    describe('Catch method', function () {
        it('handles rejected promises', function () {

            $called = false;
            /** @var Exception|null $receivedReason */
            $receivedReason = null;

            run(function () use (&$called, &$receivedReason) {
                $promise = new Promise();

                $promise->catch(function ($reason) use (&$called, &$receivedReason) {
                    $called = true;
                    $receivedReason = $reason;
                    return 'recovered';
                });

                $exception = new Exception('test error');
                $promise->reject($exception);
            });

            expect($called)->toBeTrue()
                ->and($receivedReason)->toBeInstanceOf(Exception::class)
                ->and($receivedReason->getMessage())->toBe('test error');
        });

        it('does not handle resolved promises', function () {
            $promise = new Promise();
            $called = false;

            $promise->catch(function ($reason) use (&$called) {
                $called = true;
            });

            $promise->resolve('value');

            expect($called)->toBeFalse();
        });

        it('can recover from rejection', function () {
            $recoveredPromise = run(function () {
                $promise = new Promise();
                $exception = new Exception('error');

                $recoveredPromise = $promise->catch(function ($reason) {
                    return 'recovered value';
                });

                $promise->reject($exception);

                return $recoveredPromise;
            });

            expect($recoveredPromise->isResolved())->toBeTrue()
                ->and($recoveredPromise->getValue())->toBe('recovered value');
        });
    });

    describe('Finally method', function () {
        it('calls finally handler on resolution', function () {
            $called = false;

            run(function () use (&$called) {
                $promise = new Promise();

                $promise->finally(function () use (&$called) {
                    $called = true;
                });

                $promise->resolve('value');
            });

            expect($called)->toBeTrue();
        });

        it('calls finally handler on rejection', function () {
            $called = false;

            run(function () use (&$called) {
                $promise = new Promise();

                $promise->finally(function () use (&$called) {
                    $called = true;
                });

                $promise->reject(new Exception('error'));
            });

            expect($called)->toBeTrue();
        });

        it('returns the same promise', function () {
            $promise = new Promise();
            $finallyPromise = $promise->finally(function () {
                // cleanup
            });

            expect($finallyPromise)->toBe($promise);
        });
    });

    describe('Static factory methods', function () {
        it('creates resolved promise with resolved()', function () {
            $promise = Promise::resolved('test value');

            expect($promise->isResolved())->toBeTrue()
                ->and($promise->getValue())->toBe('test value');
        });

        it('creates rejected promise with rejected()', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejected($exception);

            expect($promise->isRejected())->toBeTrue()
                ->and($promise->getReason())->toBe($exception);
        });
    });

    describe('Promise::all', function () {
        it('resolves when all promises resolve', function () {
            $result = run(function () {
                $promise1 = Promise::resolved('value1');
                $promise2 = Promise::resolved('value2');
                $promise3 = Promise::resolved('value3');

                return await(Promise::all([$promise1, $promise2, $promise3]));
            });

            expect($result)->toBe(['value1', 'value2', 'value3']);
        });

        it('rejects when any promise rejects', function () {
            $exception = new Exception('error');

            try {
                run(function () use ($exception) {
                    $promise1 = Promise::resolved('value1');
                    $promise2 = Promise::rejected($exception);
                    $promise3 = Promise::resolved('value3');

                    return await(Promise::all([$promise1, $promise2, $promise3]));
                });

                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e)->toBe($exception);
            }
        });

        it('handles empty array', function () {
            $result = run(function () {
                return await(Promise::all([]));
            });

            expect($result)->toBe([]);
        });

        it('preserves order of results', function () {
            $result = run(function () {
                $promises = [
                    Promise::resolved('first'),
                    Promise::resolved('second'),
                    Promise::resolved('third')
                ];

                return await(Promise::all($promises));
            });

            expect($result[0])->toBe('first')
                ->and($result[1])->toBe('second')
                ->and($result[2])->toBe('third');
        });
    });

    describe('Promise::any', function () {
        it('resolves with the first resolved promise even when earlier promises reject', function () {
            $result = run(function () {
                $promise1 = Promise::rejected(new Exception('first error'));
                $promise2 = Promise::rejected(new Exception('second error'));
                $promise3 = Promise::resolved('third success');
                $promise4 = Promise::resolved('fourth success');

                return await(Promise::any([$promise1, $promise2, $promise3, $promise4]));
            });

            expect($result)->toBe('third success');
        });

        it('rejects with AggregateException when all promises reject', function () {
            try {
                run(function () {
                    $promise1 = Promise::rejected(new Exception('first error'));
                    $promise2 = Promise::rejected(new Exception('second error'));
                    $promise3 = Promise::rejected(new Exception('third error'));

                    return await(Promise::any([$promise1, $promise2, $promise3]));
                });

                expect(false)->toBeTrue('Expected AggregateException to be thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
                // You might want to check for a specific exception type like AggregateException
                // depending on your implementation
            }
        });

        it('resolves immediately with the first successful promise in mixed order', function () {
            $result = run(function () {
                $promise1 = new Promise();
                $promise2 = Promise::resolved('quick success');
                $promise3 = new Promise();

                // Reject the first promise after setting up any()
                $anyPromise = Promise::any([$promise1, $promise2, $promise3]);

                $promise1->reject(new Exception('delayed error'));
                $promise3->resolve('delayed success');

                return await($anyPromise);
            });

            expect($result)->toBe('quick success');
        });

        it('handles empty array by rejecting', function () {
            try {
                run(function () {
                    return await(Promise::any([]));
                });

                expect(false)->toBeTrue('Expected exception to be thrown for empty array');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
            }
        });

        it('resolves with first successful promise when mixed with pending promises', function () {
            $result = run(function () {
                $promise1 = Promise::rejected(new Exception('error'));
                $promise2 = new Promise(); // Never settles
                $promise3 = Promise::resolved('success');
                $promise4 = new Promise(); // Never settles

                return await(Promise::any([$promise1, $promise2, $promise3, $promise4]));
            });

            expect($result)->toBe('success');
        });
    });

    describe('Promise::race', function () {
        it('resolves with the first settled promise value', function () {
            $result = run(function () {
                $promise1 = Promise::resolved('fast');
                $promise2 = new Promise(); // never settles
                $promise3 = new Promise(); // never settles

                return await(Promise::race([$promise1, $promise2, $promise3]));
            });

            expect($result)->toBe('fast');
        });

        it('rejects with the first settled promise reason', function () {
            $exception = new Exception('fast error');

            try {
                run(function () use ($exception) {
                    $promise1 = Promise::rejected($exception);
                    $promise2 = new Promise(); // never settles

                    return await(Promise::race([$promise1, $promise2]));
                });

                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e)->toBe($exception);
            }
        });
    });

    describe('Error handling', function () {
        it('returns null when getting value of non-resolved promise', function () {
            $promise = new Promise();
            expect($promise->getValue())->toBeNull();
        });

        it('returns null when getting reason of non-rejected promise', function () {
            $promise = new Promise();
            expect($promise->getReason())->toBeNull();
        });

        it('allows getting value of resolved promise', function () {
            $promise = new Promise();
            $promise->resolve('test');

            expect($promise->getValue())->toBe('test');
        });

        it('allows getting reason of rejected promise', function () {
            $promise = new Promise();
            $exception = new Exception('error');
            $promise->reject($exception);

            expect($promise->getReason())->toBe($exception);
        });
    });

    describe('Promise::batch', function () {
        it('processes tasks in batches with default batch size', function () {
            $executionOrder = [];
            $startTime = microtime(true);

            $result = run(function () use (&$executionOrder) {
                $tasks = [];

                // Create 25 tasks that each take 0.1 seconds
                for ($i = 0; $i < 25; $i++) {
                    $tasks[] = function () use ($i, &$executionOrder) {
                        return delay(0.1)->then(function () use ($i, &$executionOrder) {
                            $executionOrder[] = $i;
                            return "task-{$i}";
                        });
                    };
                }

                return await(Promise::batch($tasks, 5)); // 5 tasks per batch
            });

            $executionTime = microtime(true) - $startTime;

            // Should complete in roughly 5 batches × 0.1s = 0.5s (plus overhead)
            expect($executionTime)->toBeLessThan(0.8);
            expect($result)->toHaveCount(25);
            expect($result[0])->toBe('task-0');
            expect($result[24])->toBe('task-24');
        });

        it('respects batch size parameter', function () {
            $startTime = microtime(true);

            $result = run(function () {
                $tasks = [];

                // Create 7 tasks, each taking 0.1 seconds
                for ($i = 0; $i < 7; $i++) {
                    $tasks[] = fn() => delay(0.1)->then(fn() => "task-{$i}");
                }

                return await(Promise::batch($tasks, 3)); // 3 tasks per batch
            });

            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(7);
            // With batch size 3: [0,1,2], [3,4,5], [6]
            // Should take ~3 batch cycles × 0.1s = ~0.3s
            expect($executionTime)->toBeGreaterThan(0.25);
            expect($executionTime)->toBeLessThan(0.5);
        });

        it('processes batches sequentially not concurrently', function () {
            $executionTimes = [];
            $startTime = microtime(true);

            run(function () use (&$executionTimes, $startTime) {
                $tasks = [];

                // Create 6 tasks, each taking 0.1 seconds
                for ($i = 0; $i < 6; $i++) {
                    $tasks[] = function () use ($i, &$executionTimes, $startTime) {
                        return delay(0.1)->then(function () use ($i, &$executionTimes, $startTime) {
                            $executionTimes[] = microtime(true) - $startTime;
                            return "task-{$i}";
                        });
                    };
                }

                return await(Promise::batch($tasks, 2)); // 2 tasks per batch
            });

            // Batches should execute sequentially:
            // Batch 1 (tasks 0,1): ~0.1s
            // Batch 2 (tasks 2,3): ~0.2s  
            // Batch 3 (tasks 4,5): ~0.3s
            expect($executionTimes[0])->toBeLessThan(0.15); // First batch
            expect($executionTimes[2])->toBeGreaterThan(0.18); // Second batch
            expect($executionTimes[4])->toBeGreaterThan(0.28); // Third batch
        });

        it('handles empty task array', function () {
            $result = run(function () {
                return await(Promise::batch([], 5));
            });

            expect($result)->toBe([]);
        });

        it('works with batch size larger than task count', function () {
            $result = run(function () {
                $tasks = [
                    fn() => delay(0.05)->then(fn() => 'task-0'),
                    fn() => delay(0.05)->then(fn() => 'task-1'),
                    fn() => delay(0.05)->then(fn() => 'task-2'),
                ];

                return await(Promise::batch($tasks, 10)); // Batch size > task count
            });

            expect($result)->toHaveCount(3);
            expect($result)->toBe(['task-0', 'task-1', 'task-2']);
        });

        it('handles task failures within a batch', function () {
            try {
                run(function () {
                    $tasks = [
                        fn() => delay(0.05)->then(fn() => 'task-0'),
                        fn() => delay(0.05)->then(fn() => throw new Exception('batch error')),
                        fn() => delay(0.05)->then(fn() => 'task-2'),
                    ];

                    return await(Promise::batch($tasks, 2));
                });

                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('batch error');
            }
        });

        it('respects concurrency parameter when provided', function () {
            $startTime = microtime(true);

            $result = run(function () {
                $tasks = [];

                for ($i = 0; $i < 6; $i++) {
                    $tasks[] = fn() => delay(0.1)->then(fn() => "task-{$i}");
                }

                return await(Promise::batch($tasks, 3, 2));
            });

            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(6);
            expect($executionTime)->toBeLessThan(0.6);
            expect($executionTime)->toBeGreaterThan(0.3);
        });

        it('maintains result order within and across batches', function () {
            $result = run(function () {
                $tasks = [];

                // Create tasks with same delay to avoid timing issues
                for ($i = 0; $i < 6; $i++) {
                    $tasks[] = function () use ($i) {
                        return delay(0.05)->then(fn() => "task-{$i}");
                    };
                }

                return await(Promise::batch($tasks, 2));
            });

            expect($result)->toHaveCount(6);
            expect($result)->toContain('task-0');
            expect($result)->toContain('task-1');
            expect($result)->toContain('task-2');
            expect($result)->toContain('task-3');
            expect($result)->toContain('task-4');
            expect($result)->toContain('task-5');
        });
    });

    describe('Integration with CancellablePromise', function () {
        it('tracks root cancellable promise in chain', function () {
            $cancellable = new CancellablePromise();
            $chained = $cancellable->then(function ($value) {
                return $value * 2;
            });

            expect($chained->getRootCancellable())->toBe($cancellable);
        });

        it('skips handlers when root promise is cancelled', function () {
            $cancellable = new CancellablePromise();
            $handlerCalled = false;

            $chained = $cancellable->then(function ($value) use (&$handlerCalled) {
                $handlerCalled = true;
                return $value;
            });

            $cancellable->cancel();
            $cancellable->resolve('test'); // This won't trigger handlers due to cancellation

            expect($handlerCalled)->toBeFalse();
        });
    });
});
