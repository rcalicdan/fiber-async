<?php

namespace Rcalicdan\FiberAsync\Benchmark\Utils;

use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;

class BenchmarkOutput
{
    private BenchmarkConfig $config;
    private BenchmarkFormatter $formatter;

    public function __construct(BenchmarkConfig $config)
    {
        $this->config = $config;
        $this->formatter = new BenchmarkFormatter($config);
    }

    public function displayResults(array $results): void
    {
        if (!$this->config->isOutputEnabled()) {
            return;
        }

        echo "\n📊 BENCHMARK RESULTS\n";
        echo str_repeat("=", 50) . "\n";

        $this->displaySummary($results['summary']);

        if ($this->config->isMemoryTrackingEnabled() && !empty($results['memory'])) {
            $this->displayMemoryResults($results['memory']);
        }

        if ($this->config->isStatisticalAnalysisEnabled() && !empty($results['statistics'])) {
            $this->displayStatisticalResults($results['statistics']);
        }

        echo "\n";
    }

    public function displayComparisonHeader(): void
    {
        if (!$this->config->isOutputEnabled()) {
            return;
        }

        echo "🏆 RUNNING COMPARISON BENCHMARK\n";
        echo str_repeat("=", 60) . "\n";
    }

    public function displayComparison(array $results): void
    {
        if (!$this->config->isOutputEnabled()) {
            return;
        }

        echo "\n🏆 BENCHMARK COMPARISON RESULTS\n";
        echo str_repeat("=", 70) . "\n";

        $sortedResults = $results;
        uasort(
            $sortedResults,
            fn($a, $b) =>
            $a->getResults()['summary']['avg_time_ms'] <=>
                $b->getResults()['summary']['avg_time_ms']
        );

        $fastest = null;
        $lowestMemory = null;

        foreach ($sortedResults as $benchmark) {
            $result = $benchmark->getResults()['summary'];
            $memory = $benchmark->getResults()['memory'] ?? [];

            if ($fastest === null) {
                $fastest = $result['avg_time_ms'];
            }

            if ($this->config->isMemoryTrackingEnabled() && !empty($memory)) {
                if ($lowestMemory === null || $memory['avg_memory_net'] < $lowestMemory) {
                    $lowestMemory = $memory['avg_memory_net'];
                }
            }
        }

        $isFirstResult = true;
        foreach ($sortedResults as $name => $benchmark) {
            $result = $benchmark->getResults()['summary'];
            $memory = $benchmark->getResults()['memory'] ?? [];

            if ($isFirstResult) {
                $timeStatus = "🥇 FASTEST";
                $isFirstResult = false;
            } else {
                $speedup = $result['avg_time_ms'] / $fastest;
                $timeStatus = sprintf("%.2fx slower", $speedup);
            }

            $timeDisplay = $this->formatter->formatSummaryTime($result['avg_time_ms']);
            $output = sprintf("%-25s: %12s (%s)", $name, $timeDisplay, $timeStatus);

            if ($this->config->isMemoryTrackingEnabled() && !empty($memory)) {
                $memoryDisplay = $this->formatter->formatBytes($memory['avg_memory_net']);

                if ($lowestMemory !== null && $lowestMemory > 0) {
                    if (abs($memory['avg_memory_net'] - $lowestMemory) < 1024) {
                        $memoryStatus = "most efficient";
                    } else {
                        $memoryRatio = $memory['avg_memory_net'] / $lowestMemory;
                        $memoryStatus = sprintf("%.2fx more memory", $memoryRatio);
                    }
                } else {
                    $memoryStatus = "baseline";
                }

                $output .= sprintf(" | Mem: %s (%s)", $memoryDisplay, $memoryStatus);

                if ($memory['has_leak'] ?? false) {
                    $output .= " ⚠️ LEAK";
                }
            }

            echo $output . "\n";
        }

        if ($this->config->isMemoryTrackingEnabled()) {
            echo "\n🧠 MEMORY ANALYSIS:\n";
            echo str_repeat("-", 50) . "\n";

            foreach ($sortedResults as $name => $benchmark) {
                $memory = $benchmark->getResults()['memory'] ?? [];
                if (!empty($memory)) {
                    echo sprintf("%-25s:\n", $name);
                    echo sprintf("  Net usage: %s\n", $this->formatter->formatBytes($memory['avg_memory_net']));
                    echo sprintf("  Peak memory: %s\n", $this->formatter->formatBytes($memory['peak_memory']));
                    echo sprintf("  Initialization: %s\n", $this->formatter->formatBytes($memory['initialization_cost']));
                    echo sprintf("  Growth after init: %s\n", $this->formatter->formatBytes($memory['avg_growth_after_init']));

                    if ($memory['has_leak']) {
                        echo sprintf("  🚨 Memory leak: %s\n", $memory['leak_severity']);
                    } else {
                        echo "  ✅ No memory leak\n";
                    }
                    echo "\n";
                }
            }
        }

        if ($this->config->isUltraPrecisionEnabled()) {
            echo "Timing method: " . (function_exists('hrtime') ? 'hrtime (nanosecond precision)' : 'microtime (microsecond precision)') . "\n";
        }

        if ($this->config->isStatisticalAnalysisEnabled()) {
            $this->displayComparisonStatistics($sortedResults);
        }

        echo "\n";
    }

    private function displaySummary(array $summary): void
    {
        echo sprintf("Runs: %d\n", $summary['runs']);
        echo sprintf("Total time: %s\n", $this->formatter->formatSummaryTime($summary['total_time_ms']));
        echo sprintf("Average time: %s\n", $this->formatter->formatSummaryTime($summary['avg_time_ms']));
        echo sprintf(
            "Min/Max: %s / %s\n",
            $this->formatter->formatSummaryTime($summary['min_time_ms']),
            $this->formatter->formatSummaryTime($summary['max_time_ms'])
        );
        echo sprintf("Median: %s\n", $this->formatter->formatSummaryTime($summary['median_time_ms']));
        echo sprintf("Throughput: %.2f ops/sec\n", $summary['throughput_per_second']);
    }

    private function displayMemoryResults(array $memory): void
    {
        echo "\n🧠 MEMORY ANALYSIS:\n";
        echo sprintf("Initialization cost: %s\n", $this->formatter->formatBytes($memory['initialization_cost']));

        if ($memory['operational_runs_count'] > 0) {
            echo "\n📊 Operational Memory (per run after init):\n";
            echo sprintf("  Average net change: %s\n", $this->formatter->formatBytes($memory['avg_operational_net']));
            echo sprintf("  Average temp usage: %s\n", $this->formatter->formatBytes($memory['avg_operational_temp']));
            echo sprintf("  Max temp usage: %s\n", $this->formatter->formatBytes($memory['max_operational_temp']));
            echo sprintf("  Memory efficiency: %s\n", $memory['operational_efficiency']);
            echo sprintf("  Memory stability: %s\n", $memory['memory_stability']);
        }

        echo sprintf("\nTotal net change: %s\n", $this->formatter->formatBytes($memory['avg_memory_net']));
        echo sprintf("Peak memory: %s\n", $this->formatter->formatBytes($memory['peak_memory']));

        if ($memory['has_leak']) {
            echo sprintf(
                "\n⚠️  MEMORY LEAK: %s (%.2f KB/run)\n",
                $memory['leak_severity'],
                $memory['leak_rate_per_run'] / 1024
            );
        } else {
            echo sprintf("\n✅ No memory leak detected (%s)\n", $memory['leak_severity']);
        }
    }

    private function displayStatisticalResults(array $statistics): void
    {
        if (empty($statistics['time'])) return;

        $stats = $statistics['time'];
        echo "\n📈 STATISTICAL ANALYSIS:\n";
        echo sprintf("Standard deviation: %s\n", $this->formatter->formatSummaryTime($stats['std_dev']));
        echo sprintf("Coefficient of variation: %.2f%%\n", $stats['coefficient_of_variation'] * 100);
        echo sprintf("95th percentile: %s\n", $this->formatter->formatSummaryTime($stats['p95']));
        echo sprintf("99th percentile: %s\n", $this->formatter->formatSummaryTime($stats['p99']));
        echo sprintf("Variance: %s²\n", $this->formatter->formatSummaryTime(sqrt($stats['variance'])));
        echo sprintf("Range: %s\n", $this->formatter->formatSummaryTime($stats['max'] - $stats['min']));
    }

    private function displayComparisonStatistics(array $results): void
    {
        echo "\n📊 DETAILED STATISTICS:\n";
        echo str_repeat("-", 70) . "\n";

        foreach ($results as $name => $benchmark) {
            $benchmarkResults = $benchmark->getResults();

            if (empty($benchmarkResults['statistics']['time'])) continue;

            $stats = $benchmarkResults['statistics']['time'];
            $summary = $benchmarkResults['summary'];

            echo sprintf("📋 %s:\n", $name);
            echo sprintf("  Average: %s\n", $this->formatter->formatSummaryTime($summary['avg_time_ms']));
            echo sprintf("  Median:  %s\n", $this->formatter->formatSummaryTime($summary['median_time_ms']));
            echo sprintf(
                "  Min/Max: %s / %s\n",
                $this->formatter->formatSummaryTime($summary['min_time_ms']),
                $this->formatter->formatSummaryTime($summary['max_time_ms'])
            );
            echo sprintf(
                "  Std Dev: %s (%.1f%%)\n",
                $this->formatter->formatSummaryTime($stats['std_dev']),
                $stats['coefficient_of_variation'] * 100
            );
            echo sprintf(
                "  95%%/99%%: %s / %s\n",
                $this->formatter->formatSummaryTime($stats['p95']),
                $this->formatter->formatSummaryTime($stats['p99'])
            );

            $consistency = $this->getConsistencyRating($stats['coefficient_of_variation']);
            echo sprintf("  Consistency: %s\n", $consistency);
            echo "\n";
        }
    }

    private function getConsistencyRating(float $coefficientOfVariation): string
    {
        $cv = $coefficientOfVariation * 100;

        if ($cv < 1) return "🟢 Excellent (CV: " . number_format($cv, 1) . "%)";
        if ($cv < 5) return "🟡 Good (CV: " . number_format($cv, 1) . "%)";
        if ($cv < 10) return "🟠 Fair (CV: " . number_format($cv, 1) . "%)";
        if ($cv < 20) return "🔴 Poor (CV: " . number_format($cv, 1) . "%)";
        return "⚫ Very Poor (CV: " . number_format($cv, 1) . "%)";
    }
}
