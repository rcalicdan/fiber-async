<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\BenchmarkHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\ConcurrentExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\HttpExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\LoopExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\TaskExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\LoopOperations\TimeoutHandler;

class LoopOperations
{
    private AsyncOperations $asyncOps;
    private LoopExecutionHandler $executionHandler;
    private ConcurrentExecutionHandler $concurrentHandler;
    private TaskExecutionHandler $taskHandler;
    private HttpExecutionHandler $httpHandler;
    private TimeoutHandler $timeoutHandler;
    private BenchmarkHandler $benchmarkHandler;

    public function __construct(?AsyncOperations $asyncOps = null)
    {
        $this->asyncOps = $asyncOps ?? new AsyncOperations();
        $this->executionHandler = new LoopExecutionHandler($this->asyncOps);
        $this->concurrentHandler = new ConcurrentExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->taskHandler = new TaskExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->httpHandler = new HttpExecutionHandler($this->asyncOps, $this->executionHandler);
        $this->timeoutHandler = new TimeoutHandler($this->asyncOps, $this->executionHandler);
        $this->benchmarkHandler = new BenchmarkHandler($this->executionHandler);
    }

    /**
     * Run async operations with automatic event loop management
     */
    public function run(callable|PromiseInterface $asyncOperation): mixed
    {
        return $this->executionHandler->run($asyncOperation);
    }

    /**
     * Run multiple async operations concurrently
     */
    public function runAll(array $asyncOperations): array
    {
        return $this->concurrentHandler->runAll($asyncOperations);
    }

    /**
     * Run async operations with concurrency limit
     */
    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return $this->concurrentHandler->runConcurrent($asyncOperations, $concurrency);
    }

    /**
     * Create and run a simple async task
     */
    public function task(callable $asyncFunction): mixed
    {
        return $this->taskHandler->task($asyncFunction);
    }

    /**
     * Async delay with automatic loop management
     */
    public function asyncSleep(float $seconds): void
    {
        $this->taskHandler->asyncSleep($seconds);
    }

    /**
     * Quick HTTP fetch with automatic loop management
     */
    public function quickFetch(string $url, array $options = []): array
    {
        return $this->httpHandler->quickFetch($url, $options);
    }

    /**
     * Run with timeout
     */
    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return $this->timeoutHandler->runWithTimeout($asyncOperation, $timeout);
    }

    /**
     * Run and return both result and execution time
     */
    public function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        return $this->benchmarkHandler->benchmark($asyncOperation);
    }

    /**
     * Get formatted benchmark result
     */
    public function formatBenchmark(array $benchmarkResult): string
    {
        return $this->benchmarkHandler->formatBenchmarkResult($benchmarkResult);
    }
}