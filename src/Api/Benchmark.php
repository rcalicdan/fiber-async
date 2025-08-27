<?php

namespace Rcalicdan\FiberAsync\Api;

use InvalidArgumentException;
use Throwable;

class Benchmark
{
    private static array $globalResults = [];

    private string $name;
    private array $options = [
        'runs' => 5,
        'warmup' => 1,
        'sleep' => 0,
        'memory_tracking' => true,
        'garbage_collection' => true,
        'detailed_output' => true,
        'statistical_analysis' => false,
        'high_precision' => false,
        'isolate_runs' => false,
    ];
    private array $results = [];
    private $callback = null;

    public static function create(string $name): self
    {
        return new self($name);
    }

    public static function quick(callable $callback): float
    {
        return self::create('Quick Benchmark')
            ->callback($callback)
            ->runs(3)
            ->warmup(0)
            ->quiet()
            ->run()
            ->getAverageTime()
        ;
    }

    public static function memory(string $name, callable $callback): self
    {
        return self::create($name)
            ->callback($callback)
            ->runs(5)
            ->warmup(1)
            ->enableMemoryTracking()
            ->run()
        ;
    }

    public static function compare(array $benchmarks, array $options = []): array
    {
        $results = [];
        $defaultOptions = array_merge([
            'runs' => 5,
            'warmup' => 1,
            'quiet_individual' => true,
        ], $options);

        echo "🏆 RUNNING COMPARISON BENCHMARK\n";
        echo str_repeat('=', 60)."\n";

        foreach ($benchmarks as $name => $callback) {
            $benchmark = self::create($name)
                ->callback($callback)
                ->runs($defaultOptions['runs'])
                ->warmup($defaultOptions['warmup'])
            ;

            if ($defaultOptions['quiet_individual']) {
                $benchmark->quiet();
            }

            $results[$name] = $benchmark->run();
        }

        self::displayComparison($results);

        return $results;
    }

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function callback(callable $callback): self
    {
        $this->callback = $callback;

        return $this;
    }

    public function runs(int $runs): self
    {
        $this->options['runs'] = max(1, $runs);

        return $this;
    }

    public function warmup(int $warmup): self
    {
        $this->options['warmup'] = max(0, $warmup);

        return $this;
    }

    public function sleepBetweenRuns(float $seconds): self // Fixed: renamed to avoid conflict
    {
        $this->options['sleep'] = max(0, $seconds);

        return $this;
    }

    public function enableMemoryTracking(bool $enable = true): self
    {
        $this->options['memory_tracking'] = $enable;

        return $this;
    }

    public function enableGarbageCollection(bool $enable = true): self
    {
        $this->options['garbage_collection'] = $enable;

        return $this;
    }

    public function quiet(bool $quiet = true): self
    {
        $this->options['detailed_output'] = ! $quiet;

        return $this;
    }

    public function verbose(): self
    {
        $this->options['detailed_output'] = true;

        return $this;
    }

    public function enableStatistics(bool $enable = true): self
    {
        $this->options['statistical_analysis'] = $enable;

        return $this;
    }

    public function highPrecision(bool $enable = true): self
    {
        $this->options['high_precision'] = $enable;

        return $this;
    }

    public function isolateRuns(bool $enable = true): self
    {
        $this->options['isolate_runs'] = $enable;

        return $this;
    }

    // Execution methods
    public function run(): self
    {
        if ($this->callback === null) {
            throw new InvalidArgumentException('Callback must be set before running benchmark');
        }

        $this->results = $this->executeBenchmark();
        self::$globalResults[$this->name] = $this->results;

        if ($this->options['detailed_output']) {
            $this->displayResults();
        }

        return $this;
    }

    // Result retrieval methods
    public function getResults(): array
    {
        return $this->results;
    }

    public function getAverageTime(): float
    {
        return $this->results['summary']['avg_time_ms'] ?? 0.0;
    }

    public function getMedianTime(): float
    {
        return $this->results['summary']['median_time_ms'] ?? 0.0;
    }

    public function getTotalTime(): float
    {
        return $this->results['summary']['total_time_ms'] ?? 0.0;
    }

    public function getMemoryUsage(): array
    {
        return $this->results['memory'] ?? [];
    }

    public function hasMemoryLeak(): bool
    {
        return $this->results['memory']['has_leak'] ?? false;
    }

    public function getStatistics(): array
    {
        return $this->results['statistics'] ?? [];
    }

    public function summary(): self
    {
        $this->displaySummary();

        return $this;
    }

    // Static utility methods
    public static function getAllResults(): array
    {
        return self::$globalResults;
    }

    public static function clearResults(): void
    {
        self::$globalResults = [];
    }

    private function executeBenchmark(): array
    {
        $this->prepareEnvironment();

        if ($this->options['detailed_output']) {
            echo "🚀 Running benchmark: {$this->name}\n";
            echo str_repeat('-', 50)."\n";
        }

        $runs = [];
        $baselineMemory = 0;

        // Warmup phase
        if ($this->options['warmup'] > 0) {
            if ($this->options['detailed_output']) {
                echo "🔥 Warming up ({$this->options['warmup']} runs)...\n";
            }

            for ($i = 0; $i < $this->options['warmup']; $i++) {
                $this->executeRun($this->callback, true);
                if ($this->options['garbage_collection']) {
                    $this->forceGarbageCollection();
                }
            }
        }

        // Reset memory tracking after warmup
        if ($this->options['memory_tracking']) {
            $this->resetMemoryTracking();
            $baselineMemory = memory_get_usage(true);
        }

        // Actual benchmark runs
        for ($i = 1; $i <= $this->options['runs']; $i++) {
            if ($this->options['isolate_runs']) {
                $this->isolateRun();
            }

            $run = $this->executeRun($this->callback, false, $i, $baselineMemory);
            $runs[] = $run;

            if ($this->options['detailed_output']) {
                $this->displayRunResult($run);
            }

            if ($this->options['sleep'] > 0 && $i < $this->options['runs']) {
                $this->sleepFor($this->options['sleep']); // Fixed: use renamed method
            }
        }

        return $this->processBenchmarkResults($runs);
    }

    private function executeRun(callable $callback, bool $isWarmup = false, int $runNumber = 0, int $baselineMemory = 0): array
    {
        // Pre-run measurements
        $memoryBefore = $this->options['memory_tracking'] ? memory_get_usage(true) : 0;
        $peakBefore = $this->options['memory_tracking'] ? memory_get_peak_usage(true) : 0;

        // High precision timing
        if ($this->options['high_precision'] && function_exists('hrtime')) {
            $startTime = hrtime(true);
        } else {
            $startTime = microtime(true);
        }

        // Execute callback
        $result = null;
        $exception = null;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $exception = $e;
        }

        // Post-run measurements
        if ($this->options['high_precision'] && function_exists('hrtime')) {
            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1e6; // Convert to milliseconds
        } else {
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        }

        $memoryAfter = $this->options['memory_tracking'] ? memory_get_usage(true) : 0;
        $peakAfter = $this->options['memory_tracking'] ? memory_get_peak_usage(true) : 0;

        // Force garbage collection if enabled
        if ($this->options['garbage_collection'] && $this->options['memory_tracking']) {
            $this->forceGarbageCollection();
        }

        $memoryAfterGc = $this->options['memory_tracking'] ? memory_get_usage(true) : 0;

        return [
            'run_number' => $runNumber,
            'is_warmup' => $isWarmup,
            'duration_ms' => $duration,
            'duration_seconds' => $duration / 1000,
            'memory_before' => $memoryBefore,
            'memory_after' => $memoryAfter,
            'memory_after_gc' => $memoryAfterGc,
            'memory_delta' => $memoryAfter - $memoryBefore,
            'memory_net' => $memoryAfterGc - $baselineMemory,
            'peak_memory_delta' => $peakAfter - $peakBefore,
            'result' => $result,
            'exception' => $exception,
            'timestamp' => microtime(true),
        ];
    }

    private function processBenchmarkResults(array $runs): array
    {
        $times = array_column($runs, 'duration_ms');
        $memoryDeltas = array_column($runs, 'memory_delta');
        $memoryNets = array_column($runs, 'memory_net');

        $summary = [
            'name' => $this->name,
            'runs' => count($runs),
            'total_time_ms' => array_sum($times),
            'avg_time_ms' => array_sum($times) / count($times),
            'min_time_ms' => min($times),
            'max_time_ms' => max($times),
            'median_time_ms' => $this->median($times),
            'throughput_per_second' => count($runs) / (array_sum($times) / 1000),
        ];

        $memory = [];
        if ($this->options['memory_tracking']) {
            $memory = $this->analyzeMemoryUsage($runs);
        }

        $statistics = [];
        if ($this->options['statistical_analysis']) {
            $statistics = [
                'time' => $this->calculateStatistics($times),
                'memory' => $this->options['memory_tracking'] ? $this->calculateStatistics($memoryNets) : [],
            ];
        }

        return [
            'summary' => $summary,
            'memory' => $memory,
            'statistics' => $statistics,
            'runs' => $runs,
            'options' => $this->options,
        ];
    }

    private function analyzeMemoryUsage(array $runs): array
    {
        $memoryNets = array_column($runs, 'memory_net');
        $initializationCost = $runs[0]['memory_net'] ?? 0;

        // Calculate growth after initialization (skip first run)
        $subsequentRuns = array_slice($memoryNets, 1);
        $avgGrowthAfterInit = empty($subsequentRuns) ? 0 : array_sum($subsequentRuns) / count($subsequentRuns);

        $hasLeak = $avgGrowthAfterInit > 10240; // More than 10KB average growth

        return [
            'initialization_cost' => $initializationCost,
            'avg_memory_delta' => array_sum(array_column($runs, 'memory_delta')) / count($runs),
            'avg_memory_net' => array_sum($memoryNets) / count($memoryNets),
            'avg_growth_after_init' => $avgGrowthAfterInit,
            'has_leak' => $hasLeak,
            'leak_severity' => $this->getLeakSeverity($avgGrowthAfterInit),
            'peak_memory' => max(array_column($runs, 'memory_after')),
        ];
    }

    private function calculateStatistics(array $values): array
    {
        if (empty($values)) {
            return [];
        }

        sort($values);
        $count = count($values);
        $mean = array_sum($values) / $count;

        $variance = array_sum(array_map(function ($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / $count;

        return [
            'mean' => $mean,
            'median' => $this->median($values),
            'min' => min($values),
            'max' => max($values),
            'std_dev' => sqrt($variance),
            'variance' => $variance,
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            'coefficient_of_variation' => $mean != 0 ? sqrt($variance) / $mean : 0,
        ];
    }

    // Environment and utility methods
    private function prepareEnvironment(): void
    {
        if ($this->options['garbage_collection']) {
            $this->forceGarbageCollection();
        }

        if ($this->options['memory_tracking']) {
            $this->resetMemoryTracking();
        }
    }

    private function forceGarbageCollection(): void
    {
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches(); // PHP 7.0+
        }
    }

    private function resetMemoryTracking(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }

    private function isolateRun(): void
    {
        // Additional isolation measures
        $this->forceGarbageCollection();

        // Clear any internal caches if possible
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    private function sleepFor(float $seconds): void // Fixed: renamed from sleep()
    {
        if ($seconds >= 1) {
            sleep((int) $seconds);
            $microseconds = ($seconds - (int) $seconds) * 1000000;
            if ($microseconds > 0) {
                usleep((int) $microseconds);
            }
        } else {
            usleep((int) ($seconds * 1000000));
        }
    }

    // Display methods
    private function displayResults(): void
    {
        echo "\n📊 BENCHMARK RESULTS\n";
        echo str_repeat('=', 50)."\n";

        $summary = $this->results['summary'];
        echo sprintf("Runs: %d\n", $summary['runs']);
        echo sprintf("Total time: %.2f ms\n", $summary['total_time_ms']);
        echo sprintf("Average time: %.2f ms\n", $summary['avg_time_ms']);
        echo sprintf("Min/Max: %.2f / %.2f ms\n", $summary['min_time_ms'], $summary['max_time_ms']);
        echo sprintf("Median: %.2f ms\n", $summary['median_time_ms']);
        echo sprintf("Throughput: %.2f ops/sec\n", $summary['throughput_per_second']);

        if ($this->options['memory_tracking']) {
            $this->displayMemoryResults();
        }

        if ($this->options['statistical_analysis']) {
            $this->displayStatisticalResults();
        }

        echo "\n";
    }

    private function displayRunResult(array $run): void
    {
        $output = sprintf('Run %d: %.2f ms', $run['run_number'], $run['duration_ms']);

        if ($this->options['memory_tracking']) {
            $output .= sprintf(' (mem: %s', $this->formatBytes($run['memory_net']));
            if ($run['memory_net'] != $run['memory_delta']) {
                $output .= sprintf(', temp: %s', $this->formatBytes($run['memory_delta'] - $run['memory_net']));
            }
            $output .= ')';
        }

        if ($run['exception']) {
            $output .= ' ❌ ERROR: '.$run['exception']->getMessage();
        }

        echo $output."\n";
    }

    private function displayMemoryResults(): void
    {
        $memory = $this->results['memory'];
        echo "\n🧠 MEMORY ANALYSIS:\n";
        echo sprintf("Initialization cost: %s\n", $this->formatBytes($memory['initialization_cost']));
        echo sprintf("Average memory delta: %s\n", $this->formatBytes($memory['avg_memory_delta']));
        echo sprintf("Average net change: %s\n", $this->formatBytes($memory['avg_memory_net']));
        echo sprintf("Growth after init: %s\n", $this->formatBytes($memory['avg_growth_after_init']));
        echo sprintf("Peak memory: %s\n", $this->formatBytes($memory['peak_memory']));

        if ($memory['has_leak']) {
            echo sprintf("⚠️  MEMORY LEAK: %s\n", $memory['leak_severity']);
        } else {
            echo "✅ No memory leak detected\n";
        }
    }

    private function displayStatisticalResults(): void
    {
        $stats = $this->results['statistics']['time'];
        echo "\n📈 STATISTICAL ANALYSIS:\n";
        echo sprintf("Standard deviation: %.2f ms\n", $stats['std_dev']);
        echo sprintf("Coefficient of variation: %.2f%%\n", $stats['coefficient_of_variation'] * 100);
        echo sprintf("95th percentile: %.2f ms\n", $stats['p95']);
        echo sprintf("99th percentile: %.2f ms\n", $stats['p99']);
    }

    private function displaySummary(): void
    {
        if (empty($this->results)) {
            echo "No results to display. Run the benchmark first.\n";

            return;
        }

        $this->displayResults();
    }

    private static function displayComparison(array $results): void
    {
        echo "\n🏆 BENCHMARK COMPARISON RESULTS\n";
        echo str_repeat('=', 70)."\n";

        // Sort by average time
        $sortedResults = $results;
        uasort(
            $sortedResults,
            fn ($a, $b) => $a->getResults()['summary']['avg_time_ms'] <=>
            $b->getResults()['summary']['avg_time_ms']
        );

        $fastest = null;
        foreach ($sortedResults as $name => $benchmark) {
            $result = $benchmark->getResults()['summary'];

            if ($fastest === null) {
                $fastest = $result['avg_time_ms'];
                $status = '🥇 FASTEST';
            } else {
                $speedup = $result['avg_time_ms'] / $fastest;
                $status = sprintf('%.2fx slower', $speedup);
            }

            echo sprintf("%-25s: %8.2f ms (%s)\n", $name, $result['avg_time_ms'], $status);
        }

        echo "\n";
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function percentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);

        if (floor($index) == $index) {
            return $values[(int) $index];
        }

        $lower = $values[floor($index)];
        $upper = $values[ceil($index)];

        return $lower + ($upper - $lower) * ($index - floor($index));
    }

    private function getLeakSeverity(float $avgGrowth): string
    {
        if ($avgGrowth > 1024 * 1024) {
            return 'SEVERE (>1MB per run)';
        }
        if ($avgGrowth > 1024 * 100) {
            return 'MODERATE (>100KB per run)';
        }
        if ($avgGrowth > 1024 * 10) {
            return 'MINOR (>10KB per run)';
        }

        return 'NEGLIGIBLE (<10KB per run)';
    }

    private function formatBytes(int|float $bytes): string
    {
        if ($bytes == 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string) abs($bytes)) - 1) / 3);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
