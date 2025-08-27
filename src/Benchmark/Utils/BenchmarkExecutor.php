<?php

namespace Rcalicdan\FiberAsync\Benchmark\Utils;

use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;
use Throwable;

class BenchmarkExecutor
{
    private BenchmarkConfig $config;
    private BenchmarkEnvironment $environment;

    public function __construct(BenchmarkConfig $config)
    {
        $this->config = $config;
        $this->environment = new BenchmarkEnvironment($config);
    }

    public function execute(string $name, callable $callback): array
    {
        $this->environment->prepare();

        if ($this->config->isOutputEnabled()) {
            echo "🚀 Running benchmark: {$name}\n";
            echo str_repeat('-', 50)."\n";
        }

        $runs = [];
        $baselineMemory = 0;

        // Warmup phase
        if ($this->config->getWarmup() > 0) {
            $this->executeWarmup($callback);
        }

        // Reset memory tracking after warmup
        if ($this->config->isMemoryTrackingEnabled()) {
            $this->environment->resetMemoryTracking();
            $baselineMemory = memory_get_usage(true);
        }

        // Actual benchmark runs
        for ($i = 1; $i <= $this->config->getRuns(); $i++) {
            if ($this->config->isIsolateRunsEnabled()) {
                $this->environment->isolateRun();
            }

            $run = $this->executeRun($callback, false, $i, $baselineMemory);
            $runs[] = $run;

            if ($this->config->isOutputEnabled()) {
                $this->displayRunResult($run);
            }

            if ($this->config->getSleep() > 0 && $i < $this->config->getRuns()) {
                $this->environment->sleep($this->config->getSleep());
            }
        }

        return $runs;
    }

    private function executeWarmup(callable $callback): void
    {
        if ($this->config->isOutputEnabled()) {
            echo "🔥 Warming up ({$this->config->getWarmup()} runs)...\n";
        }

        for ($i = 0; $i < $this->config->getWarmup(); $i++) {
            $this->executeRun($callback, true);
            if ($this->config->isGarbageCollectionEnabled()) {
                $this->environment->forceGarbageCollection();
            }
        }
    }

    private function executeRun(callable $callback, bool $isWarmup = false, int $runNumber = 0, int $baselineMemory = 0): array
    {
        // Pre-run measurements
        $memoryBefore = $this->config->isMemoryTrackingEnabled() ? memory_get_usage(true) : 0;
        $peakBefore = $this->config->isMemoryTrackingEnabled() ? memory_get_peak_usage(true) : 0;

        // Timing
        $startTime = $this->config->isHighPrecisionEnabled() && function_exists('hrtime')
            ? hrtime(true)
            : microtime(true);

        // Execute callback
        $result = null;
        $exception = null;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $exception = $e;
        }

        // Post-run measurements
        $duration = $this->calculateDuration($startTime);
        $memoryAfter = $this->config->isMemoryTrackingEnabled() ? memory_get_usage(true) : 0;
        $peakAfter = $this->config->isMemoryTrackingEnabled() ? memory_get_peak_usage(true) : 0;

        // Force garbage collection if enabled
        if ($this->config->isGarbageCollectionEnabled() && $this->config->isMemoryTrackingEnabled()) {
            $this->environment->forceGarbageCollection();
        }

        $memoryAfterGc = $this->config->isMemoryTrackingEnabled() ? memory_get_usage(true) : 0;

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

    private function calculateDuration(float|int $startTime): float
    {
        if ($this->config->isHighPrecisionEnabled() && function_exists('hrtime')) {
            $endTime = hrtime(true);

            return ($endTime - $startTime) / 1e6; 
        } else {
            $endTime = microtime(true);

            return ($endTime - $startTime) * 1000; 
        }
    }

    private function displayRunResult(array $run): void
    {
        $output = sprintf('Run %d: %.2f ms', $run['run_number'], $run['duration_ms']);

        if ($this->config->isMemoryTrackingEnabled()) {
            $formatter = new BenchmarkFormatter;
            $output .= sprintf(' (mem: %s', $formatter->formatBytes($run['memory_net']));
            if ($run['memory_net'] != $run['memory_delta']) {
                $output .= sprintf(', temp: %s', $formatter->formatBytes($run['memory_delta'] - $run['memory_net']));
            }
            $output .= ')';
        }

        if ($run['exception']) {
            $output .= ' ❌ ERROR: '.$run['exception']->getMessage();
        }

        echo $output."\n";
    }
}
