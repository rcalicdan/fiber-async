<?php

use Rcalicdan\FiberAsync\Promise\Promise;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

describe('Promise', function () {
    beforeEach(function () {
        // Reset any static state before each test
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

            expect($promise->getReason())->toBe('simple error message');
        });
    });

    describe('Then method', function () {
        it('calls onFulfilled when promise is resolved', function () {
            $promise = new Promise();
            $called = false;
            $receivedValue = null;

            $promise->then(function ($value) use (&$called, &$receivedValue) {
                $called = true;
                $receivedValue = $value;
            });

            $promise->resolve('test value');

            expect($called)->toBeTrue()
                ->and($receivedValue)->toBe('test value');
        });

        it('calls onRejected when promise is rejected', function () {
            $promise = new Promise();
            $called = false;
            $receivedReason = null;

            $promise->then(null, function ($reason) use (&$called, &$receivedReason) {
                $called = true;
                $receivedReason = $reason;
            });

            $exception = new Exception('test error');
            $promise->reject($exception);

            expect($called)->toBeTrue()
                ->and($receivedReason)->toBe($exception);
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
            $promise = new Promise();
            
            $finalPromise = $promise->then(function ($value) {
                return $value * 2;
            })->then(function ($value) {
                return $value + 1;
            });

            $promise->resolve(5);

            expect($finalPromise->isResolved())->toBeTrue()
                ->and($finalPromise->getValue())->toBe(11); // (5 * 2) + 1
        });

        it('handles promise returning from onFulfilled', function () {
            $promise = new Promise();
            $innerPromise = new Promise();
            
            $chainedPromise = $promise->then(function ($value) use ($innerPromise) {
                return $innerPromise;
            });

            $promise->resolve('original');
            expect($chainedPromise->isPending())->toBeTrue();

            $innerPromise->resolve('inner value');
            expect($chainedPromise->isResolved())->toBeTrue()
                ->and($chainedPromise->getValue())->toBe('inner value');
        });

        it('handles exceptions in onFulfilled', function () {
            $promise = new Promise();
            $exception = new Exception('handler error');
            
            $chainedPromise = $promise->then(function ($value) use ($exception) {
                throw $exception;
            });

            $promise->resolve('value');

            expect($chainedPromise->isRejected())->toBeTrue()
                ->and($chainedPromise->getReason())->toBe($exception);
        });

        it('calls handlers for already resolved promises', function () {
            $promise = new Promise();
            $promise->resolve('test value');

            $called = false;
            $receivedValue = null;

            $promise->then(function ($value) use (&$called, &$receivedValue) {
                $called = true;
                $receivedValue = $value;
            });

            expect($called)->toBeTrue()
                ->and($receivedValue)->toBe('test value');
        });

        it('supports multiple then handlers', function () {
            $promise = new Promise();
            $calls = [];

            $promise->then(function ($value) use (&$calls) {
                $calls[] = 'first: ' . $value;
            });

            $promise->then(function ($value) use (&$calls) {
                $calls[] = 'second: ' . $value;
            });

            $promise->resolve('test');

            expect($calls)->toHaveCount(2)
                ->and($calls)->toContain('first: test')
                ->and($calls)->toContain('second: test');
        });
    });

    describe('Catch method', function () {
        it('handles rejected promises', function () {
            $promise = new Promise();
            $called = false;
            $receivedReason = null;

            $promise->catch(function ($reason) use (&$called, &$receivedReason) {
                $called = true;
                $receivedReason = $reason;
                return 'recovered';
            });

            $exception = new Exception('test error');
            $promise->reject($exception);

            expect($called)->toBeTrue()
                ->and($receivedReason)->toBe($exception);
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
            $promise = new Promise();
            $exception = new Exception('error');
            
            $recoveredPromise = $promise->catch(function ($reason) {
                return 'recovered value';
            });

            $promise->reject($exception);

            expect($recoveredPromise->isResolved())->toBeTrue()
                ->and($recoveredPromise->getValue())->toBe('recovered value');
        });
    });

    describe('Finally method', function () {
        it('calls finally handler on resolution', function () {
            $promise = new Promise();
            $called = false;

            $promise->finally(function () use (&$called) {
                $called = true;
            });

            $promise->resolve('value');

            expect($called)->toBeTrue();
        });

        it('calls finally handler on rejection', function () {
            $promise = new Promise();
            $called = false;

            $promise->finally(function () use (&$called) {
                $called = true;
            });

            $promise->reject(new Exception('error'));

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
            $promise1 = Promise::resolved('value1');
            $promise2 = Promise::resolved('value2');
            $promise3 = Promise::resolved('value3');

            $allPromise = Promise::all([$promise1, $promise2, $promise3]);

            expect($allPromise->isResolved())->toBeTrue()
                ->and($allPromise->getValue())->toBe(['value1', 'value2', 'value3']);
        });

        it('rejects when any promise rejects', function () {
            $promise1 = Promise::resolved('value1');
            $exception = new Exception('error');
            $promise2 = Promise::rejected($exception);
            $promise3 = Promise::resolved('value3');

            $allPromise = Promise::all([$promise1, $promise2, $promise3]);

            expect($allPromise->isRejected())->toBeTrue()
                ->and($allPromise->getReason())->toBe($exception);
        });

        it('handles empty array', function () {
            $allPromise = Promise::all([]);

            expect($allPromise->isResolved())->toBeTrue()
                ->and($allPromise->getValue())->toBe([]);
        });

        it('preserves order of results', function () {
            $promises = [
                Promise::resolved('first'),
                Promise::resolved('second'),
                Promise::resolved('third')
            ];

            $allPromise = Promise::all($promises);
            $results = $allPromise->getValue();

            expect($results[0])->toBe('first')
                ->and($results[1])->toBe('second')
                ->and($results[2])->toBe('third');
        });
    });

    describe('Promise::race', function () {
        it('resolves with the first settled promise value', function () {
            $promise1 = Promise::resolved('fast');
            $promise2 = new Promise(); // never settles
            $promise3 = new Promise(); // never settles

            $racePromise = Promise::race([$promise1, $promise2, $promise3]);

            expect($racePromise->isResolved())->toBeTrue()
                ->and($racePromise->getValue())->toBe('fast');
        });

        it('rejects with the first settled promise reason', function () {
            $exception = new Exception('fast error');
            $promise1 = Promise::rejected($exception);
            $promise2 = new Promise(); // never settles

            $racePromise = Promise::race([$promise1, $promise2]);

            expect($racePromise->isRejected())->toBeTrue()
                ->and($racePromise->getReason())->toBe($exception);
        });

        it('handles empty array by staying pending', function () {
            $racePromise = Promise::race([]);

            expect($racePromise->isPending())->toBeTrue();
        });
    });

    describe('Error handling', function () {
        it('throws LogicException when getting value of non-resolved promise', function () {
            $promise = new Promise();
            
            expect(fn() => $promise->getValue())
                ->toThrow(LogicException::class);
        });

        it('throws LogicException when getting reason of non-rejected promise', function () {
            $promise = new Promise();
            
            expect(fn() => $promise->getReason())
                ->toThrow(LogicException::class);
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