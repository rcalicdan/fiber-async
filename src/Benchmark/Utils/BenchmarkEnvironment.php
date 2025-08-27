<?php

namespace Rcalicdan\FiberAsync\Benchmark\Utils;

use Rcalicdan\FiberAsync\Benchmark\BenchmarkConfig;

class BenchmarkEnvironment
{
    private BenchmarkConfig $config;

    public function __construct(BenchmarkConfig $config)
    {
        $this->config = $config;
    }

    public function prepare(): void
    {
        if ($this->config->isGarbageCollectionEnabled()) {
            $this->forceGarbageCollection();
        }

        if ($this->config->isMemoryTrackingEnabled()) {
            $this->resetMemoryTracking();
        }
    }

    public function forceGarbageCollection(): void
    {
        gc_collect_cycles();
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches(); // PHP 7.0+
        }
    }

    public function resetMemoryTracking(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
    }

    public function isolateRun(): void
    {
        // Additional isolation measures
        $this->forceGarbageCollection();

        // Clear any internal caches if possible
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    public function sleep(float $seconds): void
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
}
