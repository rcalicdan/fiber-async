<?php

namespace Rcalicdan\FiberAsync\Benchmark\Utils;

use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;

class BenchmarkFormatter
{
    private BenchmarkConfig $config;

    public function __construct(?BenchmarkConfig $config = null)
    {
        $this->config = $config ?? new BenchmarkConfig;
    }

    public function formatTime(array $run): string
    {
        $decimals = $this->config->getPrecisionDecimals();

        if ($this->config->shouldShowNanoseconds()) {
            return number_format($run['duration_nanoseconds'], 0).' ns';
        } elseif ($this->config->shouldShowMicroseconds()) {
            return number_format($run['duration_microseconds'], $decimals).' μs';
        } else {
            return number_format($run['duration_ms'], $decimals).' ms';
        }
    }

    public function formatSummaryTime(float $timeMs): string
    {
        $decimals = $this->config->getPrecisionDecimals();

        if ($this->config->shouldShowNanoseconds()) {
            $nanoseconds = $timeMs * 1_000_000;

            return number_format($nanoseconds, 0).' ns';
        } elseif ($this->config->shouldShowMicroseconds()) {
            $microseconds = $timeMs * 1000;

            return number_format($microseconds, $decimals).' μs';
        } else {
            return number_format($timeMs, $decimals).' ms';
        }
    }

    public function formatBytes(int|float $bytes): string
    {
        if ($bytes == 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string) abs($bytes)) - 1) / 3);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
}
