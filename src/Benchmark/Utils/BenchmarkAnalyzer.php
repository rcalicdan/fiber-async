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

        // Analyze operational runs (excluding first run)
        $operationalRuns = array_slice($runs, 1);
        $operationalMetrics = $this->analyzeOperationalMemory($operationalRuns);

        // True leak detection
        $leakAnalysis = $this->detectMemoryLeak($runs);

        // Overall averages
        $avgMemoryDelta = array_sum($memoryDeltas) / count($memoryDeltas);
        $avgMemoryNet = array_sum($memoryNets) / count($memoryNets);
        $avgRetained = array_sum($memoryRetained) / count($memoryRetained);

        return [
            'initialization_cost' => $initializationCost,
            'avg_memory_delta' => $avgMemoryDelta,
            'avg_memory_net' => $avgMemoryNet,
            'avg_memory_retained' => $avgRetained,
            'operational_runs_count' => count($operationalRuns),
            'avg_operational_delta' => $operationalMetrics['avg_delta'],
            'avg_operational_net' => $operationalMetrics['avg_net'],
            'avg_operational_temp' => $operationalMetrics['avg_temp'],
            'max_operational_temp' => $operationalMetrics['max_temp'],
            'operational_efficiency' => $operationalMetrics['efficiency'],
            'has_leak' => $leakAnalysis['has_leak'],
            'leak_severity' => $leakAnalysis['severity'],
            'leak_rate_per_run' => $leakAnalysis['rate_per_run'],
            'peak_memory' => max(array_column($runs, 'memory_after')),
            'peak_memory_after_gc' => max(array_column($runs, 'memory_after_gc')),
            'memory_stability' => $this->calculateMemoryStability($runs),
        ];
    }

    private function analyzeOperationalMemory(array $operationalRuns): array
    {
        if (empty($operationalRuns)) {
            return [
                'avg_delta' => 0,
                'avg_net' => 0,
                'avg_temp' => 0,
                'max_temp' => 0,
                'efficiency' => 'N/A'
            ];
        }

        $deltas = array_column($operationalRuns, 'memory_delta');
        $nets = array_column($operationalRuns, 'memory_net');

        // Calculate temporary memory usage (delta - net)
        $temps = [];
        foreach ($operationalRuns as $run) {
            $temps[] = $run['memory_delta'] - $run['memory_net'];
        }

        $avgDelta = array_sum($deltas) / count($deltas);
        $avgNet = array_sum($nets) / count($nets);
        $avgTemp = array_sum($temps) / count($temps);
        $maxTemp = empty($temps) ? 0 : max($temps);

        // Memory efficiency: how much temp memory vs permanent
        $efficiency = $avgDelta != 0 ? abs($avgTemp / $avgDelta) : 1.0;
        $efficiencyRating = $this->getEfficiencyRating($efficiency);

        return [
            'avg_delta' => $avgDelta,
            'avg_net' => $avgNet,
            'avg_temp' => $avgTemp,
            'max_temp' => $maxTemp,
            'efficiency' => $efficiencyRating
        ];
    }

    private function calculateMemoryStability(array $runs): string
    {
        if (count($runs) < 2) return 'N/A';

        $nets = array_slice(array_column($runs, 'memory_net'), 1); // Skip first run

        if (empty($nets)) return 'N/A';

        $variance = 0;
        $mean = array_sum($nets) / count($nets);

        foreach ($nets as $net) {
            $variance += pow($net - $mean, 2);
        }

        $variance = $variance / count($nets);
        $stdDev = sqrt($variance);
        $cv = $mean != 0 ? $stdDev / abs($mean) : 0;

        if ($cv < 0.01) return 'Excellent (very stable)';
        if ($cv < 0.05) return 'Good (stable)';
        if ($cv < 0.1) return 'Fair (mostly stable)';
        return 'Poor (unstable)';
    }

    private function getEfficiencyRating(float $efficiency): string
    {
        if ($efficiency > 0.9) return 'Excellent (>90% cleanup)';
        if ($efficiency > 0.8) return 'Good (>80% cleanup)';
        if ($efficiency > 0.6) return 'Fair (>60% cleanup)';
        if ($efficiency > 0.4) return 'Poor (>40% cleanup)';
        return 'Very Poor (<40% cleanup)';
    }

    private function detectMemoryLeak(array $runs): array
    {
        if (count($runs) < 3) {
            return ['has_leak' => false, 'severity' => 'N/A (insufficient runs)', 'rate_per_run' => 0];
        }

        // Skip first run (initialization) and analyze trend
        $operationalRuns = array_slice($runs, 1);
        $memoryProgression = [];

        // Track memory after each run (after GC to see retained memory)
        foreach ($operationalRuns as $run) {
            $memoryProgression[] = $run['memory_retained'];
        }

        // Calculate linear regression to detect consistent growth
        $runNumbers = range(1, count($memoryProgression));
        $slope = $this->calculateSlope($runNumbers, $memoryProgression);

        // A positive slope indicates consistent memory growth
        $leakThreshold = 1024; 
        $hasLeak = $slope > $leakThreshold;

        return [
            'has_leak' => $hasLeak,
            'severity' => $this->getLeakSeverity($slope),
            'rate_per_run' => $slope
        ];
    }

    private function calculateSlope(array $x, array $y): float
    {
        $n = count($x);
        if ($n < 2) return 0;

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumXX += $x[$i] * $x[$i];
        }

        $denominator = ($n * $sumXX - $sumX * $sumX);
        if ($denominator == 0) return 0;

        return ($n * $sumXY - $sumX * $sumY) / $denominator;
    }

    private function getLeakSeverity(float $ratePerRun): string
    {
        if ($ratePerRun < 1024) return "NO LEAK";
        if ($ratePerRun > 1024 * 1024) return "SEVERE (>" . $this->formatBytes(1024 * 1024) . " per run)";
        if ($ratePerRun > 1024 * 100) return "MODERATE (>" . $this->formatBytes(1024 * 100) . " per run)";
        if ($ratePerRun > 1024 * 10) return "MINOR (>" . $this->formatBytes(1024 * 10) . " per run)";
        return "NEGLIGIBLE (<" . $this->formatBytes(1024 * 10) . " per run)";
    }

    private function formatBytes(int|float $bytes): string
    {
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string)abs($bytes)) - 1) / 3);
        return sprintf("%.2f %s", $bytes / (1024 ** $factor), $units[$factor]);
    }

    private function analyzeStatistics(array $runs): array
    {
        $calculator = new BenchmarkCalculator();
        $times = array_column($runs, 'duration_ms');
        $memoryNets = array_column($runs, 'memory_net');

        $timeStats = $calculator->calculateStatistics($times);

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
}
