<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Promise\Promise;

class FiberAsyncAveragingPerformanceTest
{
    private array $endpoints;
    private array $runResults = [];

    public function __construct()
    {
        $this->endpoints = [
            'posts' => 'https://jsonplaceholder.typicode.com/posts',
            'posts/1' => 'https://jsonplaceholder.typicode.com/posts/1',
            'posts/1/comments' => 'https://jsonplaceholder.typicode.com/posts/1/comments',
            'albums' => 'https://jsonplaceholder.typicode.com/albums',
            'albums/1' => 'https://jsonplaceholder.typicode.com/albums/1',
            'albums/1/photos' => 'https://jsonplaceholder.typicode.com/albums/1/photos',
            'photos' => 'https://jsonplaceholder.typicode.com/photos',
            'photos/1' => 'https://jsonplaceholder.typicode.com/photos/1',
            'todos' => 'https://jsonplaceholder.typicode.com/todos',
            'todos/1' => 'https://jsonplaceholder.typicode.com/todos/1',
            'users' => 'https://jsonplaceholder.typicode.com/users',
            'users/1' => 'https://jsonplaceholder.typicode.com/users/1',
            'users/1/albums' => 'https://jsonplaceholder.typicode.com/users/1/albums',
            'users/1/todos' => 'https://jsonplaceholder.typicode.com/users/1/todos',
            'users/1/posts' => 'https://jsonplaceholder.typicode.com/users/1/posts',
            'comments' => 'https://jsonplaceholder.typicode.com/comments',
            'comments/1' => 'https://jsonplaceholder.typicode.com/comments/1',
            'posts?userId=1' => 'https://jsonplaceholder.typicode.com/posts?userId=1',
            'albums?userId=1' => 'https://jsonplaceholder.typicode.com/albums?userId=1',
            'todos?userId=1' => 'https://jsonplaceholder.typicode.com/todos?userId=1',
        ];
    }

    public function runTest(int $totalRuns = 10): void
    {
        echo "Starting Averaged API Performance Test with FiberAsync ({$totalRuns} runs)\n";
        echo "===================================================================\n\n";

        // Reset peak memory tracking
        memory_reset_peak_usage();

        for ($i = 1; $i <= $totalRuns; $i++) {
            echo "--- Starting Run #{$i} ---\n";

            // ✅ Measure memory OUTSIDE the task
            $initialMemory = memory_get_usage(true);
            $initialPeak = memory_get_peak_usage(true);
            $startTime = microtime(true);

            Task::run(function () {
                $promises = [];
                foreach ($this->endpoints as $name => $url) {
                    $promises[$name] = Http::get($url);
                }
                await(Promise::all($promises));
            });

            // ✅ Measure after task completion
            $endTime = microtime(true);
            $finalMemory = memory_get_usage(true);
            $finalPeak = memory_get_peak_usage(true);

            // Force garbage collection to see what's actually retained
            gc_collect_cycles();
            $afterGcMemory = memory_get_usage(true);

            $this->runResults[] = [
                'time' => ($endTime - $startTime) * 1000,
                'memory_before' => $initialMemory,
                'memory_after' => $finalMemory,
                'memory_after_gc' => $afterGcMemory,
                'memory_increase' => $finalMemory - $initialMemory,
                'memory_increase_after_gc' => $afterGcMemory - $initialMemory,
                'peak_memory_this_run' => $finalPeak - $initialPeak,
                'total_peak_memory' => $finalPeak,
            ];

            echo sprintf("Time: %.2f ms\n", ($endTime - $startTime) * 1000);
            echo sprintf(
                "Memory: %s -> %s (increase: %s)\n",
                $this->formatBytes($initialMemory),
                $this->formatBytes($finalMemory),
                $this->formatBytes($finalMemory - $initialMemory)
            );
            echo sprintf(
                "After GC: %s (net change: %s)\n",
                $this->formatBytes($afterGcMemory),
                $this->formatBytes($afterGcMemory - $initialMemory)
            );
            echo sprintf(
                "Peak increase this run: %s\n",
                $this->formatBytes($finalPeak - $initialPeak)
            );
            echo "--- Run #{$i} Complete ---\n\n";

            if ($i < $totalRuns) {
                sleep(1);
            }
        }

        $this->displayAveragedResults($totalRuns);
    }

    private function displayAveragedResults(int $totalRuns): void
    {
        $avgTime = array_sum(array_column($this->runResults, 'time')) / $totalRuns;
        $avgMemIncrease = array_sum(array_column($this->runResults, 'memory_increase')) / $totalRuns;

        // ✅ Calculate leak detection excluding first run (which includes initialization)
        $subsequentRuns = array_slice($this->runResults, 1); // Skip first run
        $avgMemIncreaseAfterInit = empty($subsequentRuns) ? 0 :
            array_sum(array_column($subsequentRuns, 'memory_increase_after_gc')) / count($subsequentRuns);

        $avgPeakIncrease = array_sum(array_column($this->runResults, 'peak_memory_this_run')) / $totalRuns;

        echo "\n".str_repeat('=', 70)."\n";
        echo "AVERAGE PERFORMANCE SUMMARY ({$totalRuns} RUNS)\n";
        echo str_repeat('=', 70)."\n";

        echo sprintf("Average execution time: %.2f ms\n", $avgTime);
        echo sprintf("Average memory increase: %s\n", $this->formatBytes($avgMemIncrease));
        echo sprintf("Average peak memory per run: %s\n", $this->formatBytes($avgPeakIncrease));
        echo sprintf("Effective throughput: %.2f requests/second\n", count($this->endpoints) / ($avgTime / 1000));

        // ✅ Better leak analysis
        $firstRunInitialization = $this->runResults[0]['memory_increase_after_gc'];
        echo sprintf("First run initialization cost: %s\n", $this->formatBytes($firstRunInitialization));
        echo sprintf("Average memory growth after initialization: %s\n", $this->formatBytes($avgMemIncreaseAfterInit));

        if ($avgMemIncreaseAfterInit > 10240) { // More than 10KB per run after init
            echo "\n⚠️  MEMORY LEAK DETECTED: Average ".$this->formatBytes($avgMemIncreaseAfterInit)." retained per run after initialization\n";
        } else {
            echo "\n✅ NO MEMORY LEAK: Memory stable after initial setup (".$this->formatBytes($avgMemIncreaseAfterInit)." average growth)\n";
        }

        echo "\n".str_repeat('-', 70)."\n";
        echo "Individual Run Details:\n";
        foreach ($this->runResults as $index => $result) {
            $status = $index === 0 ? ' (initialization)' : '';
            echo sprintf(
                "Run %d: %.2f ms, %s->%s (net: %s after GC)%s\n",
                $index + 1,
                $result['time'],
                $this->formatBytes($result['memory_before']),
                $this->formatBytes($result['memory_after']),
                $this->formatBytes($result['memory_increase_after_gc']),
                $status
            );
        }
        echo str_repeat('-', 70)."\n";
    }

    private function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        if ($bytes == 0) {
            return '0.00 B';
        }
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}

try {
    $test = new FiberAsyncAveragingPerformanceTest;
    $test->runTest();
} catch (Exception $e) {
    echo 'Test failed: '.$e->getMessage()."\n";
}
