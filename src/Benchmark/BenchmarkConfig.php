<?php

namespace Rcalicdan\FiberAsync\Benchmark;

class BenchmarkConfig
{
    private array $options = [
        'runs' => 5,
        'warmup' => 1,
        'sleep' => 0,
        'memory_tracking' => true,
        'garbage_collection' => true,
        'output_enabled' => true,
        'statistical_analysis' => false,
        'high_precision' => false,
        'isolate_runs' => false,
        'ultra_precision' => false,
        'precision_decimals' => 2,
        'show_nanoseconds' => false,
        'show_microseconds' => false,
        'force_hrtime' => false,
        'filter_outliers' => false,
        'outlier_threshold' => 2.0,
        'min_runs_after_filter' => 100,
    ];

    public static function create(): self
    {
        return new self;
    }

    public static function fromArray(array $options): self
    {
        $config = new self;
        foreach ($options as $key => $value) {
            if (array_key_exists($key, $config->options)) {
                $config->options[$key] = $value;
            }
        }

        return $config;
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

    public function sleepBetweenRuns(float $seconds): self
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

    public function silent(bool $silent = true): self
    {
        $this->options['output_enabled'] = ! $silent;

        return $this;
    }

    public function outputEnabled(bool $enabled = true): self
    {
        $this->options['output_enabled'] = $enabled;

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

    // New ultra precision methods
    public function ultraPrecision(bool $enable = true): self
    {
        $this->options['ultra_precision'] = $enable;
        $this->options['high_precision'] = $enable; // Enable high precision too
        $this->options['force_hrtime'] = $enable;

        return $this;
    }

    public function precisionDecimals(int $decimals): self
    {
        $this->options['precision_decimals'] = max(0, min(9, $decimals));

        return $this;
    }

    public function showNanoseconds(bool $enable = true): self
    {
        $this->options['show_nanoseconds'] = $enable;
        if ($enable) {
            $this->options['show_microseconds'] = false;
            $this->options['precision_decimals'] = 0;
        }

        return $this;
    }

    public function showMicroseconds(bool $enable = true): self
    {
        $this->options['show_microseconds'] = $enable;
        if ($enable) {
            $this->options['show_nanoseconds'] = false;
            $this->options['precision_decimals'] = max(3, $this->options['precision_decimals']);
        }

        return $this;
    }

    public function forceHrtime(bool $enable = true): self
    {
        $this->options['force_hrtime'] = $enable;

        return $this;
    }

    public function isolateRuns(bool $enable = true): self
    {
        $this->options['isolate_runs'] = $enable;

        return $this;
    }

    public function filterOutliers(bool $enable = true): self
    {
        $this->options['filter_outliers'] = $enable;
        return $this;
    }

    public function outlierThreshold(float $threshold): self
    {
        $this->options['outlier_threshold'] = max(1.0, $threshold);
        return $this;
    }

    public function minRunsAfterFilter(int $runs): self
    {
        $this->options['min_runs_after_filter'] = max(10, $runs);
        return $this;
    }

    public function shouldFilterOutliers(): bool
    {
        return $this->options['filter_outliers'];
    }

    public function getOutlierThreshold(): float
    {
        return $this->options['outlier_threshold'];
    }
    
    public function getMinRunsAfterFilter(): int
    {
        return $this->options['min_runs_after_filter'];
    }

    public function getRuns(): int
    {
        return $this->options['runs'];
    }

    public function getWarmup(): int
    {
        return $this->options['warmup'];
    }

    public function getSleep(): float
    {
        return $this->options['sleep'];
    }

    public function isMemoryTrackingEnabled(): bool
    {
        return $this->options['memory_tracking'];
    }

    public function isGarbageCollectionEnabled(): bool
    {
        return $this->options['garbage_collection'];
    }

    public function isOutputEnabled(): bool
    {
        return $this->options['output_enabled'];
    }

    public function isStatisticalAnalysisEnabled(): bool
    {
        return $this->options['statistical_analysis'];
    }

    public function isHighPrecisionEnabled(): bool
    {
        return $this->options['high_precision'];
    }

    public function isUltraPrecisionEnabled(): bool
    {
        return $this->options['ultra_precision'];
    }

    public function isIsolateRunsEnabled(): bool
    {
        return $this->options['isolate_runs'];
    }

    public function getPrecisionDecimals(): int
    {
        return $this->options['precision_decimals'];
    }

    public function shouldShowNanoseconds(): bool
    {
        return $this->options['show_nanoseconds'];
    }

    public function shouldShowMicroseconds(): bool
    {
        return $this->options['show_microseconds'];
    }

    public function shouldForceHrtime(): bool
    {
        return $this->options['force_hrtime'];
    }

    public function toArray(): array
    {
        return $this->options;
    }
}
