<?php

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

beforeEach(function () {
    // Clean up event loop before each test
    if (method_exists(EventLoop::class, 'reset')) {
        EventLoop::reset();
    }
});

afterEach(function () {
    // Clean up event loop after each test
    if (method_exists(EventLoop::class, 'reset')) {
        EventLoop::reset();
    }
});

describe('CancellablePromise', function () {

    it('implements CancellablePromiseInterface', function () {
        $promise = new CancellablePromise();

        expect($promise)->toBeInstanceOf(CancellablePromiseInterface::class);
    });

    it('starts in pending state', function () {
        $promise = new CancellablePromise();

        expect($promise->isPending())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isCancelled())->toBeFalse();
    });

    it('can be resolved with a value', function () {
        $promise = new CancellablePromise();
        $testValue = 'test result';

        $promise->resolve($testValue);

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->getValue())->toBe($testValue);
    });

    it('can be rejected with a reason', function () {
        $promise = new CancellablePromise();
        $testReason = new Exception('test error');

        $promise->reject($testReason);

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->getReason())->toBe($testReason);
    });

    it('can be cancelled', function () {
        $promise = new CancellablePromise();

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
            ->and($promise->isPending())->toBeFalse();
    });

    it('becomes rejected when cancelled', function () {
        $promise = new CancellablePromise();

        $promise->cancel();

        expect($promise->isRejected())->toBeTrue();
        expect($promise->getReason())->toBeInstanceOf(Exception::class);
        expect($promise->getReason()->getMessage())->toBe('Promise cancelled');
    });

    it('executes cancel handler when cancelled', function () {
        $promise = new CancellablePromise();
        $handlerExecuted = false;

        $promise->setCancelHandler(function () use (&$handlerExecuted) {
            $handlerExecuted = true;
        });

        $promise->cancel();

        expect($handlerExecuted)->toBeTrue();
    });

    it('sets and uses timer ID for cancellation', function () {
        $promise = new CancellablePromise();
        $timerId = 'test-timer-123';

        // Test that setTimerId doesn't throw an error
        $promise->setTimerId($timerId);

        // Test that cancellation works (timer cancellation happens internally)
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('ignores multiple cancellation attempts', function () {
        $promise = new CancellablePromise();
        $cancelCount = 0;

        $promise->setCancelHandler(function () use (&$cancelCount) {
            $cancelCount++;
        });

        $promise->cancel();
        $promise->cancel();
        $promise->cancel();

        expect($cancelCount)->toBe(1)
            ->and($promise->isCancelled())->toBeTrue();
    });

    it('cannot be resolved after cancellation', function () {
        $promise = new CancellablePromise();

        $promise->cancel();
        $promise->resolve('test value');

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeTrue();
    });

    it('cannot be rejected after cancellation', function () {
        $promise = new CancellablePromise();

        $promise->cancel();
        $promise->reject(new Exception('new error'));

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue();
        // The reason should still be the cancellation exception
        expect($promise->getReason()->getMessage())->toBe('Promise cancelled');
    });

    it('handles cancel handler exceptions gracefully', function () {
        $promise = new CancellablePromise();

        $promise->setCancelHandler(function () {
            throw new Exception('Handler error');
        });

        // This should not throw an exception (error is logged)
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('works with executor function', function () {
        $resolved = false;

        $promise = new CancellablePromise(function ($resolve, $reject) use (&$resolved) {
            $resolve('executor result');
            $resolved = true;
        });

        expect($resolved)->toBeTrue()
            ->and($promise->isResolved())->toBeTrue()
            ->and($promise->getValue())->toBe('executor result');
    });

    it('can be cancelled even after executor resolved it', function () {
        $promise = new CancellablePromise(function ($resolve, $reject) {
            $resolve('executor result');
        });

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->getValue())->toBe('executor result');

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isResolved())->toBeTrue()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->getValue())->toBe('executor result');
    });

    it('can be cancelled before executor resolves', function () {
        $resolveCallback = null;

        $promise = new CancellablePromise(function ($resolve, $reject) use (&$resolveCallback) {
            // Store the resolve callback but don't call it immediately
            $resolveCallback = $resolve;
        });

        // Cancel before resolving
        $promise->cancel();

        // Now try to resolve (should have no effect)
        if ($resolveCallback) {
            $resolveCallback('executor result');
        }

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse();
    });

    it('supports promise chaining', function () {
        $promise = new CancellablePromise();

        // Chain the promise - returns a regular Promise, not CancellablePromise
        $chainedPromise = $promise->then(function ($value) {
            return $value . ' processed';
        });

        // Resolve the original promise
        $promise->resolve('initial');

        // The chained promise should be a regular Promise interface
        expect($chainedPromise)->toBeInstanceOf(PromiseInterface::class);
        expect($chainedPromise)->not->toBeInstanceOf(CancellablePromiseInterface::class);
    });

    it('handles cancellation in promise chain', function () {
        $promise = new CancellablePromise();
        $thenCalled = false;
        $catchCalled = false;

        $chainedPromise = $promise->then(function ($value) use (&$thenCalled) {
            $thenCalled = true;
            return $value . ' processed';
        })->catch(function ($reason) use (&$catchCalled) {
            $catchCalled = true;
            return 'caught: ' . $reason->getMessage();
        });

        $promise->cancel();

        // The original promise should be cancelled
        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue();

        // Chain returns regular Promise interface
        expect($chainedPromise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('maintains cancellation state through chain', function () {
        $promise = new CancellablePromise();

        $chainedPromise = $promise->then(function ($value) {
            return $value . ' processed';
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        // Test that the chained promise has access to the root cancellable
        // (this depends on the internal implementation)
        if (method_exists($chainedPromise, 'getRootCancellable')) {
            expect($chainedPromise->getRootCancellable())->toBe($promise);
        }
    });

    it('can set finally callback', function () {
        $promise = new CancellablePromise();
        $finallyCalled = false;

        $finalPromise = $promise->finally(function () use (&$finallyCalled) {
            $finallyCalled = true;
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
        expect($finalPromise)->toBeInstanceOf(PromiseInterface::class);
    });
});

// Integration tests with helper functions
describe('CancellablePromise Integration', function () {

    it('works with delay function', function () {
        $promise = delay(0.1);

        expect($promise)->toBeInstanceOf(CancellablePromiseInterface::class);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('can create timeout operations', function () {
        $slowOperation = function () {
            return delay(1.0); // 1 second delay
        };

        $promise = timeout($slowOperation, 0.1);

        expect($promise)->toBeInstanceOf(PromiseInterface::class);

        // Only test cancellation if it's actually cancellable
        if ($promise instanceof CancellablePromiseInterface) {
            $promise->cancel();
            expect($promise->isCancelled())->toBeTrue();
        }
    });

    it('works with async file operations', function () {
        $promise = read_file_async('/nonexistent/file.txt');

        expect($promise)->toBeInstanceOf(CancellablePromiseInterface::class);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('can cancel file write operations', function () {
        $promise = write_file_async('/tmp/test.txt', 'test data');

        expect($promise)->toBeInstanceOf(CancellablePromiseInterface::class);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('works with concurrent operations', function () {
        $tasks = [
            fn() => delay(0.1),
            fn() => delay(0.2),
            fn() => delay(0.3),
        ];

        $promise = concurrent($tasks, 2);

        // concurrent() returns a regular Promise, not CancellablePromise
        expect($promise)->toBeInstanceOf(PromiseInterface::class);

        // Only test cancellation if it's actually cancellable
        if ($promise instanceof CancellablePromiseInterface) {
            $promise->cancel();
            expect($promise->isCancelled())->toBeTrue();
        }
    });
});

// Performance and edge case tests
describe('CancellablePromise Edge Cases', function () {

    it('handles rapid cancel and resolve attempts', function () {
        $promise = new CancellablePromise();

        // Try to cancel and resolve at the same time
        $promise->cancel();
        $promise->resolve('value');
        $promise->cancel(); // Second cancel
        $promise->reject(new Exception('error'));

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse();
    });

    it('handles multiple cancel handlers', function () {
        $promise = new CancellablePromise();
        $callCount = 0;

        // Set multiple handlers (last one overwrites)
        $promise->setCancelHandler(function () use (&$callCount) {
            $callCount += 1;
        });
        $promise->setCancelHandler(function () use (&$callCount) {
            $callCount += 10;
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
        expect($callCount)->toBe(10); // Only last handler should execute
    });

    it('works with different timer ID formats', function () {
        $promise = new CancellablePromise();
        $promise->setTimerId('simple-id');
        $promise->setTimerId('complex-id-123-456');
        $promise->setTimerId('uuid-like-f47ac10b-58cc-4372-a567-0e02b2c3d479');

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('maintains state consistency under stress', function () {
        $promises = [];

        for ($i = 0; $i < 10; $i++) {
            $promises[] = new CancellablePromise();
        }

        foreach ($promises as $promise) {
            $promise->cancel();
        }

        foreach ($promises as $promise) {
            expect($promise->isCancelled())->toBeTrue();
        }
    });

    it('can handle cancellation with empty timer ID', function () {
        $promise = new CancellablePromise();

        $promise->setTimerId('');

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('can handle cancellation with null cancel handler initially', function () {
        $promise = new CancellablePromise();

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });
});

describe('CancellablePromise setCancelHandler Usage', function () {

    it('executes cleanup operations when cancelled', function () {
        $promise = new CancellablePromise();
        $connectionClosed = false;
        $tempFileDeleted = false;
        $resourcesFreed = false;

        $promise->setCancelHandler(function () use (&$connectionClosed, &$tempFileDeleted, &$resourcesFreed) {
            // Simulate cleanup operations
            $connectionClosed = true;
            $tempFileDeleted = true;
            $resourcesFreed = true;
        });

        $promise->cancel();

        expect($connectionClosed)->toBeTrue()
            ->and($tempFileDeleted)->toBeTrue()
            ->and($resourcesFreed)->toBeTrue();
    });

    it('handles database connection cleanup', function () {
        $promise = new CancellablePromise();
        $dbConnection = new stdClass();
        $dbConnection->isConnected = true;
        $dbConnection->transactionActive = true;

        $promise->setCancelHandler(function () use ($dbConnection) {
            // Rollback transaction and close connection
            $dbConnection->transactionActive = false;
            $dbConnection->isConnected = false;
        });

        $promise->cancel();

        expect($dbConnection->transactionActive)->toBeFalse()
            ->and($dbConnection->isConnected)->toBeFalse();
    });

    it('handles file stream cleanup', function () {
        $promise = new CancellablePromise();
        $fileHandle = new stdClass();
        $fileHandle->isOpen = true;
        $tempFiles = ['temp1.txt', 'temp2.txt'];
        $cleanedFiles = [];

        $promise->setCancelHandler(function () use ($fileHandle, $tempFiles, &$cleanedFiles) {
            // Close file handle
            $fileHandle->isOpen = false;

            // Clean up temporary files
            foreach ($tempFiles as $file) {
                $cleanedFiles[] = $file;
            }
        });

        $promise->cancel();

        expect($fileHandle->isOpen)->toBeFalse()
            ->and($cleanedFiles)->toBe(['temp1.txt', 'temp2.txt']);
    });

    it('handles HTTP request cancellation', function () {
        $promise = new CancellablePromise();
        $httpRequest = new stdClass();
        $httpRequest->isActive = true;
        $httpRequest->aborted = false;

        $promise->setCancelHandler(function () use ($httpRequest) {
            // Abort HTTP request
            $httpRequest->isActive = false;
            $httpRequest->aborted = true;
        });

        $promise->cancel();

        expect($httpRequest->isActive)->toBeFalse()
            ->and($httpRequest->aborted)->toBeTrue();
    });

    it('handles timer cleanup with external resources', function () {
        $promise = new CancellablePromise();
        $timers = ['timer1', 'timer2', 'timer3'];
        $cancelledTimers = [];
        $eventLoopStopped = false;

        $promise->setCancelHandler(function () use ($timers, &$cancelledTimers, &$eventLoopStopped) {
            // Cancel all associated timers
            foreach ($timers as $timer) {
                $cancelledTimers[] = $timer;
            }
            // Stop event loop if needed
            $eventLoopStopped = true;
        });

        $promise->cancel();

        expect($cancelledTimers)->toBe(['timer1', 'timer2', 'timer3'])
            ->and($eventLoopStopped)->toBeTrue();
    });

    it('handles complex resource cleanup chain', function () {
        $promise = new CancellablePromise();
        $cleanupLog = [];

        $promise->setCancelHandler(function () use (&$cleanupLog) {
            // Simulate complex cleanup sequence
            $cleanupLog[] = '1. Saving current state';
            $cleanupLog[] = '2. Rolling back database transaction';
            $cleanupLog[] = '3. Closing network connections';
            $cleanupLog[] = '4. Releasing memory buffers';
            $cleanupLog[] = '5. Notifying dependent services';
            $cleanupLog[] = '6. Cleanup completed';
        });

        $promise->cancel();

        expect($cleanupLog)->toBe([
            '1. Saving current state',
            '2. Rolling back database transaction',
            '3. Closing network connections',
            '4. Releasing memory buffers',
            '5. Notifying dependent services',
            '6. Cleanup completed'
        ]);
    });

    it('can access promise context in cancel handler', function () {
        $promise = new CancellablePromise();
        $promiseId = 'task-123';
        $cancelContext = null;

        // Instead of adding dynamic property, use closure variables
        $contextData = ['id' => $promiseId, 'type' => 'file-upload'];

        $promise->setCancelHandler(function () use ($contextData, &$cancelContext) {
            // Access context data through closure
            $cancelContext = [
                'cancelled_task' => $contextData['id'],
                'task_type' => $contextData['type'],
                'cancel_time' => date('Y-m-d H:i:s')
            ];
        });

        $promise->cancel();

        expect($cancelContext['cancelled_task'])->toBe('task-123')
            ->and($cancelContext['task_type'])->toBe('file-upload')
            ->and($cancelContext['cancel_time'])->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
    });

    it('can chain multiple cleanup operations', function () {
        $promise = new CancellablePromise();
        $operations = [];

        // Simulate a complex async operation that needs multiple cleanups
        $promise->setCancelHandler(function () use (&$operations) {
            try {
                $operations[] = 'abort-network-request';
                // Simulate network cleanup

                $operations[] = 'close-file-handles';
                // Simulate file cleanup

                $operations[] = 'release-memory';
                // Simulate memory cleanup

                $operations[] = 'notify-completion';
                // Simulate notification
            } catch (Exception $e) {
                $operations[] = 'cleanup-error: ' . $e->getMessage();
            }
        });

        $promise->cancel();

        expect($operations)->toBe([
            'abort-network-request',
            'close-file-handles',
            'release-memory',
            'notify-completion'
        ]);
    });

    it('handles cancellation with logging and metrics', function () {
        $promise = new CancellablePromise();
        $metrics = [];
        $logs = [];

        $promise->setCancelHandler(function () use (&$metrics, &$logs) {
            // Log cancellation
            $logs[] = '[WARN] Promise cancelled by user';
            $logs[] = '[INFO] Cleaning up resources';

            // Update metrics
            $metrics['cancelled_operations'] = ($metrics['cancelled_operations'] ?? 0) + 1;
            $metrics['last_cancel_time'] = time();

            $logs[] = '[INFO] Cleanup completed successfully';
        });

        $promise->cancel();

        expect($logs)->toContain('[WARN] Promise cancelled by user')
            ->and($logs)->toContain('[INFO] Cleaning up resources')
            ->and($logs)->toContain('[INFO] Cleanup completed successfully')
            ->and($metrics['cancelled_operations'])->toBe(1)
            ->and($metrics['last_cancel_time'])->toBeInt();
    });

    it('can use closure variables for state tracking', function () {
        $activeConnections = 3;
        $processingTasks = 5;

        $promise = new CancellablePromise();

        $promise->setCancelHandler(function () use (&$activeConnections, &$processingTasks) {
            // Clean up active resources
            $activeConnections = 0;
            $processingTasks = 0;
        });

        $promise->cancel();

        expect($activeConnections)->toBe(0)
            ->and($processingTasks)->toBe(0);
    });

    it('overwrites previous cancel handler when called multiple times', function () {
        $promise = new CancellablePromise();
        $handler1Called = false;
        $handler2Called = false;
        $handler3Called = false;

        // Set first handler
        $promise->setCancelHandler(function () use (&$handler1Called) {
            $handler1Called = true;
        });

        // Set second handler (overwrites first)
        $promise->setCancelHandler(function () use (&$handler2Called) {
            $handler2Called = true;
        });

        // Set third handler (overwrites second)
        $promise->setCancelHandler(function () use (&$handler3Called) {
            $handler3Called = true;
        });

        $promise->cancel();

        // Only the last handler should be called
        expect($handler1Called)->toBeFalse()
            ->and($handler2Called)->toBeFalse()
            ->and($handler3Called)->toBeTrue();
    });

    it('can be used with real-world async file operations', function () {
        $promise = write_file_async('/tmp/large-file.txt', 'large content data');
        $cleanupExecuted = false;

        $promise->setCancelHandler(function () use (&$cleanupExecuted) {
            // Cleanup: delete partially written file
            $cleanupExecuted = true;
        });

        $promise->cancel();

        expect($cleanupExecuted)->toBeTrue()
            ->and($promise->isCancelled())->toBeTrue();
    });

    it('can be used with delay operations', function () {
        $promise = delay(1.0); // 1 second delay
        $timeoutCleared = false;

        $promise->setCancelHandler(function () use (&$timeoutCleared) {
            // Clear the underlying timer
            $timeoutCleared = true;
        });

        $promise->cancel();

        expect($timeoutCleared)->toBeTrue()
            ->and($promise->isCancelled())->toBeTrue();
    });
});

// Real-world usage examples
describe('CancellablePromise Real-World Examples', function () {

    it('file upload with progress tracking', function () {
        $uploadProgress = 0;
        $uploadCancelled = false;
        $tempFileDeleted = false;

        $uploadPromise = new CancellablePromise(function ($resolve, $reject) use (&$uploadProgress) {
            // Simulate upload progress (normally this would be async)
            $uploadProgress = 25;
            // Upload continues...
        });

        $uploadPromise->setCancelHandler(function () use (&$uploadCancelled, &$tempFileDeleted) {
            // Cancel upload and cleanup
            $uploadCancelled = true;
            $tempFileDeleted = true; // Delete temporary file
        });

        // User clicks cancel
        $uploadPromise->cancel();

        expect($uploadCancelled)->toBeTrue()
            ->and($tempFileDeleted)->toBeTrue()
            ->and($uploadPromise->isCancelled())->toBeTrue();
    });

    it('database transaction with rollback', function () {
        $transactionStarted = false;
        $transactionRolledBack = false;

        $dbPromise = new CancellablePromise(function ($resolve, $reject) use (&$transactionStarted) {
            $transactionStarted = true;
        });

        $dbPromise->setCancelHandler(function () use (&$transactionRolledBack) {
            $transactionRolledBack = true;
        });

        $dbPromise->cancel();

        expect($transactionStarted)->toBeTrue()
            ->and($transactionRolledBack)->toBeTrue()
            ->and($dbPromise->isCancelled())->toBeTrue();
    });

    it('API request with connection cleanup', function () {
        $requestSent = false;
        $connectionClosed = false;
        $cacheCleared = false;

        $apiPromise = new CancellablePromise(function ($resolve, $reject) use (&$requestSent) {
            $requestSent = true;
        });

        $apiPromise->setCancelHandler(function () use (&$connectionClosed, &$cacheCleared) {
            $connectionClosed = true;
            $cacheCleared = true;
        });

        $apiPromise->cancel();

        expect($requestSent)->toBeTrue()
            ->and($connectionClosed)->toBeTrue()
            ->and($cacheCleared)->toBeTrue();
    });
});
