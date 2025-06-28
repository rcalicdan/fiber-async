<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles benchmarking of async operations.
 * 
 * This class provides functionality to measure the execution time
 * of async operations and format the results for display.
 * 
 * @package Rcalicdan\FiberAsync\Handlers\LoopOperations
 * @author  Rcalicdan
 */
final readonly class BenchmarkHandler
{
    /**
     * Loop execution handler for running async operations.
     * 
     * @var LoopExecutionHandler
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Initialize the benchmark handler.
     * 
     * @param LoopExecutionHandler $executionHandler Handler for executing operations
     */
    public function __construct(LoopExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
    }

    /**
     * Benchmark an async operation and return timing results.
     * 
     * Executes the given async operation while measuring its execution time
     * and returns both the result and timing information.
     * 
     * @param callable|PromiseInterface $asyncOperation The operation to benchmark
     * @return array Associative array with 'result', 'duration', and 'duration_ms' keys
     */
    public function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        $start = microtime(true);
        $result = $this->executionHandler->run($asyncOperation);
        $duration = microtime(true) - $start;

        return [
            'result' => $result,
            'duration' => $duration,
            'duration_ms' => round($duration * 1000, 2),
        ];
    }

    /**
     * Format benchmark results into a human-readable string.
     * 
     * Creates a formatted string showing the operation duration
     * in both milliseconds and seconds.
     * 
     * @param array $benchmarkResult Result array from benchmark() method
     * @return string Formatted timing information
     */
    public function formatBenchmarkResult(array $benchmarkResult): string
    {
        return sprintf(
            "Operation completed in %.2fms (%.6fs)",
            $benchmarkResult['duration_ms'],
            $benchmarkResult['duration']
        );
    }
}