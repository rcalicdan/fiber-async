<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles benchmarking of async operations.
 *
 * This class provides functionality to measure the execution time
 * of async operations and format the results for display.
 */
final readonly class BenchmarkHandler
{
    /**
     * Loop execution handler for running async operations.
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Initialize the benchmark handler.
     *
     * @param  LoopExecutionHandler  $executionHandler  Handler for executing operations
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
     * @param  callable|PromiseInterface  $asyncOperation  The operation to benchmark
     * @return array Associative array with 'result', 'duration', and 'duration_ms' keys
     */
    public function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        $startMemory = memory_get_usage();
        $start = microtime(true);
        $result = $this->executionHandler->run($asyncOperation);
        $duration = microtime(true) - $start;
        $memoryUsed = memory_get_usage() - $startMemory;

        return [
            'result' => $result,
            'benchmark' => [
                'execution_time' => $duration,
                'duration_ms' => round($duration * 1000, 2),
                'memory_used' => $memoryUsed,
                'peak_memory' => memory_get_peak_usage(),
            ]
        ];
    }

    /**
     * Format benchmark results into a human-readable string.
     *
     * Creates a formatted string showing the operation duration
     * in both milliseconds and seconds.
     *
     * @param  array  $benchmarkResult  Result array from benchmark() method
     * @return string Formatted timing information
     */
    public function formatBenchmarkResult(array $benchmarkResult): string
    {
        return sprintf(
            'Operation completed in %.2fms (%.6fs)',
            $benchmarkResult['duration_ms'],
            $benchmarkResult['duration']
        );
    }
}
