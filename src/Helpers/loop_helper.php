<?php

use FiberAsync\AsyncEventLoop;
use FiberAsync\Interfaces\PromiseInterface;

/**
 * Run async operations with automatic event loop management
 */
function run(callable|PromiseInterface $asyncOperation): mixed
{
    $loop = AsyncEventLoop::getInstance();
    $result = null;
    $error = null;
    $completed = false;

    $promise = is_callable($asyncOperation)
        ? async($asyncOperation)()
        : $asyncOperation;

    $promise
        ->then(function ($value) use (&$result, &$completed) {
            $result = $value;
            $completed = true;
        })
        ->catch(function ($reason) use (&$error, &$completed) {
            $error = $reason;
            $completed = true;
        });

    // Run the event loop until completion
    while (!$completed && !$loop->isIdle()) {
        $loop->run();
        if ($completed) break;

        // Small sleep to prevent busy waiting
        usleep(1000); // 1ms
    }

    if ($error !== null) {
        throw $error instanceof \Throwable ? $error : new \Exception((string)$error);
    }

    return $result;
}

/**
 * Run multiple async operations concurrently
 */
function runAll(array $asyncOperations): array
{
    return run(function () use ($asyncOperations) {
        $promises = [];

        foreach ($asyncOperations as $key => $operation) {
            if (is_callable($operation)) {
                $promises[$key] = async($operation)();
            } else {
                $promises[$key] = $operation;
            }
        }

        return await(all($promises));
    });
}

/**
 * Run async operations with concurrency limit
 */
function runConcurrent(array $asyncOperations, int $concurrency = 10): array
{
    return run(function () use ($asyncOperations, $concurrency) {
        return await(concurrent($asyncOperations, $concurrency));
    });
}

/**
 * Create and run a simple async task
 */
function task(callable $asyncFunction): mixed
{
    return run(async($asyncFunction));
}

/**
 * Quick HTTP fetch with automatic loop management
 */
function quickFetch(string $url, array $options = []): array
{
    return run(function () use ($url, $options) {
        return await(fetch($url, $options));
    });
}

/**
 * Quick delay with automatic loop management
 */
function sleep(float $seconds): void
{
    run(function () use ($seconds) {
        await(delay($seconds));
    });
}

/**
 * Run with timeout
 */
function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
{
    return run(function () use ($asyncOperation, $timeout) {
        $promise = is_callable($asyncOperation) ? async($asyncOperation)() : $asyncOperation;

        $timeoutPromise = async(function () use ($timeout) {
            await(delay($timeout));
            throw new \Exception("Operation timed out after {$timeout} seconds");
        })();

        return await(race([$promise, $timeoutPromise]));
    });
}

/**
 * Run and return both result and execution time
 */
function benchmark(callable|PromiseInterface $asyncOperation): array
{
    $start = microtime(true);
    $result = run($asyncOperation);
    $duration = microtime(true) - $start;

    return [
        'result' => $result,
        'duration' => $duration,
        'duration_ms' => round($duration * 1000, 2)
    ];
}
