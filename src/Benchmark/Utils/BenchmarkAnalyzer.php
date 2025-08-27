<?php

namespace Rcalicdan\FiberAsync\Benchmark\Utils;

use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;

class BenchmarkAnalyzer
{
    private BenchmarkConfig $config;

    public function __construct(BenchmarkConfig $config)
    {
        $this->config = $config;
    }

    public function analyze(array $runs): array
    {
        // Filter outliers if enabled
        if ($this->config->shouldFilterOutliers()) {
            $runs = $this->filterOutliers($runs);
        }

        $times = array_column($runs, 'duration_ms');

        $summary = $this->analyzeTiming($runs, $times);
        $memory = $this->config->isMemoryTrackingEnabled() ? $this->analyzeMemoryUsage($runs) : [];
        $statistics = $this->config->isStatisticalAnalysisEnabled() ? $this->analyzeStatistics($runs) : [];

        return [
            'summary' => $summary,
            'memory' => $memory,
            'statistics' => $statistics,
            'runs' => $runs,
            'options' => $this->config->toArray()
        ];
    }

    private function filterOutliers(array $runs): array
    {
        $times = array_column($runs, 'duration_ms');

        if (count($times) < 50) {
            return $runs;
        }

        $mean = array_sum($times) / count($times);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $times)) / count($times);
        $stdDev = sqrt($variance);

        $threshold = $this->config->getOutlierThreshold();
        $filteredRuns = [];
        $outliersRemoved = 0;

        foreach ($runs as $run) {
            $zScore = abs(($run['duration_ms'] - $mean) / $stdDev);

            if ($zScore <= $threshold) {
                $filteredRuns[] = $run;
            } else {
                $outliersRemoved++;
            }
        }

        if (count($filteredRuns) < $this->config->getMinRunsAfterFilter()) {
            return $this->filterOutliersWithThreshold($runs, $threshold * 1.5);
        }

        if (!empty($filteredRuns)) {
            $filteredRuns[0]['outliers_removed'] = $outliersRemoved;
            $filteredRuns[0]['original_run_count'] = count($runs);
        }

        return $filteredRuns;
    }

    private function filterOutliersWithThreshold(array $runs, float $threshold): array
    {
        $times = array_column($runs, 'duration_ms');
        $mean = array_sum($times) / count($times);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $times)) / count($times);
        $stdDev = sqrt($variance);

        $filteredRuns = [];
        $outliersRemoved = 0;

        foreach ($runs as $run) {
            $zScore = abs(($run['duration_ms'] - $mean) / $stdDev);

            if ($zScore <= $threshold) {
                $filteredRuns[] = $run;
            } else {
                $outliersRemoved++;
            }
        }

        if (!empty($filteredRuns)) {
            $filteredRuns[0]['outliers_removed'] = $outliersRemoved;
            $filteredRuns[0]['original_run_count'] = count($runs);
        }

        return $filteredRuns;
    }


    private function analyzeTiming(array $runs, array $times): array
    {
        $calculator = new BenchmarkCalculator();

        return [
            'name' => '',
            'runs' => count($runs),
            'total_time_ms' => array_sum($times),
            'avg_time_ms' => array_sum($times) / count($times),
            'min_time_ms' => min($times),
            'max_time_ms' => max($times),
            'median_time_ms' => $calculator->median($times),
            'throughput_per_second' => count($runs) / (array_sum($times) / 1000),
        ];
    }

    private function analyzeMemoryUsage(array $runs): array
    {
        $memoryNets = array_column($runs, 'memory_net'); 
        $memoryDeltas = array_column($runs, 'memory_delta');
        $memoryRetained = array_column($runs, 'memory_retained');

        $initializationCost = $runs[0]['memory_net'] ?? 0;

        $subsequentRuns = array_slice($memoryNets, 1);
        $avgGrowthAfterInit = empty($subsequentRuns) ? 0 : array_sum($subsequentRuns) / count($subsequentRuns);

        $hasLeak = $avgGrowthAfterInit > 10240; 

        return [
            'initialization_cost' => $initializationCost,
            'avg_memory_delta' => array_sum($memoryDeltas) / count($memoryDeltas), 
            'avg_memory_net' => array_sum($memoryNets) / count($memoryNets), 
            'avg_memory_retained' => array_sum($memoryRetained) / count($memoryRetained), 
            'avg_growth_after_init' => $avgGrowthAfterInit,
            'has_leak' => $hasLeak,
            'leak_severity' => $this->getLeakSeverity($avgGrowthAfterInit),
            'peak_memory' => max(array_column($runs, 'memory_after')), 
            'peak_memory_after_gc' => max(array_column($runs, 'memory_after_gc')) 
        ];
    }

    private function analyzeStatistics(array $runs): array
    {
        $calculator = new BenchmarkCalculator();
        $times = array_column($runs, 'duration_ms');
        $memoryNets = array_column($runs, 'memory_net');

        $timeStats = $calculator->calculateStatistics($times);

        // Add additional statistics
        $timeStats['runs'] = count($runs);
        $timeStats['consistency_rating'] = $this->getConsistencyRating($timeStats['coefficient_of_variation'] ?? 0);

        $result = [
            'time' => $timeStats
        ];

        if ($this->config->isMemoryTrackingEnabled()) {
            $result['memory'] = $calculator->calculateStatistics($memoryNets);
        }

        return $result;
    }

    private function getConsistencyRating(float $coefficientOfVariation): string
    {
        $cv = $coefficientOfVariation * 100;

        if ($cv < 1) return "Excellent";
        if ($cv < 5) return "Good";
        if ($cv < 10) return "Fair";
        if ($cv < 20) return "Poor";
        return "Very Poor";
    }

    private function getLeakSeverity(float $avgGrowth): string
    {
        if ($avgGrowth > 1024 * 1024) return "SEVERE (>1MB per run)";
        if ($avgGrowth > 1024 * 100) return "MODERATE (>100KB per run)";
        if ($avgGrowth > 1024 * 10) return "MINOR (>10KB per run)";
        return "NEGLIGIBLE (<10KB per run)";
    }
}
