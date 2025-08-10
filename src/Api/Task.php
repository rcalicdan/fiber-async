<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\Loop\LoopOperations;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Static API for event loop management and high-level async execution.
 *
 * This API provides convenient methods for running async operations with
 * automatic event loop lifecycle management. It handles starting, running,
 * and stopping the event loop automatically, making it ideal for simple
 * async workflows and batch processing.
 */
final class Task
{
    /**
     * @var AsyncOperations|null Cached instance of core async operations handler.
     */
    private static ?AsyncOperations $asyncOps = null;

    /**
     * @var LoopOperations|null Cached instance of loop operations handler.
     */
    private static ?LoopOperations $loopOps = null;

    /**
     * Get the singleton instance of AsyncOperations with lazy initialization.
     *
     * @return AsyncOperations The core async operations handler.
     */
    protected static function getAsyncOperations(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations;
        }

        return self::$asyncOps;
    }

    /**
     * Get the singleton instance of LoopOperations with lazy initialization.
     *
     * @return LoopOperations The loop operations handler with automatic lifecycle management.
     */
    protected static function getLoopOperations(): LoopOperations
    {
        if (self::$loopOps === null) {
            self::$loopOps = new LoopOperations(self::getAsyncOperations());
        }

        return self::$loopOps;
    }

    /**
     * Reset all cached instances to their initial state.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
        self::$loopOps = null;
    }

    /**
     * Run an async operation with automatic event loop management.
     *
     * @param callable(): mixed|PromiseInterface<mixed> $asyncOperation The operation to execute.
     * @return mixed The result of the async operation.
     */
    public static function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return self::getLoopOperations()->run($asyncOperation);
    }

    /**
     * Run multiple async operations concurrently with automatic loop management.
     *
     * @param array<int|string, callable(): mixed|PromiseInterface<mixed>> $asyncOperations Array of callables or promises to execute.
     * @return array<mixed> Results of all operations in the same order as input.
     */
    public static function runAll(array $asyncOperations): array
    {
        return self::getLoopOperations()->runAll($asyncOperations);
    }

    /**
     * Run async operations with concurrency control and automatic loop management.
     *
     * @param array<int|string, callable(): mixed|PromiseInterface<mixed>> $asyncOperations Array of operations to execute.
     * @param int $concurrency Maximum number of concurrent operations.
     * @return array<mixed> Results of all operations.
     */
    public static function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return self::getLoopOperations()->runConcurrent($asyncOperations, $concurrency);
    }

    /**
     * Run an async operation with a timeout constraint and automatic loop management.
     *
     * @param callable(): mixed|PromiseInterface<mixed>|array<int|string, callable(): mixed|PromiseInterface<mixed>> $asyncOperation The operation to execute.
     * @param float $timeout Maximum time to wait in seconds.
     * @return mixed The result of the operation if completed within timeout.
     *
     * @throws \Exception If the operation times out.
     */
    public static function runWithTimeout(callable|PromiseInterface|array $asyncOperation, float $timeout): mixed
    {
        return self::getLoopOperations()->runWithTimeout($asyncOperation, $timeout);
    }

    /**
     * Run async operations in batches with concurrency control and automatic loop management.
     *
     * @param array<int|string, callable(): mixed|PromiseInterface<mixed>> $asyncOperations Array of operations to execute.
     * @param int $batch Number of operations to run in each batch.
     * @param int|null $concurrency Maximum number of concurrent operations per batch.
     * @return array<mixed> Results of all operations.
     */
    public static function runBatch(array $asyncOperations, int $batch, ?int $concurrency = null): array
    {
        return self::getLoopOperations()->runBatch($asyncOperations, $batch, $concurrency);
    }
}