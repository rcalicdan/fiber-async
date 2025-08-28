<?php

namespace Rcalicdan\FiberAsync\Benchmark;

use InvalidArgumentException;
use Rcalicdan\FiberAsync\Benchmark\Utils\BenchmarkOutput;

class BenchmarkComparison
{
    private array $benchmarks = [];
    private BenchmarkConfig $config;

    public function __construct()
    {
        $this->config = BenchmarkConfig::create();
    }

    public function runs(int $runs): self
    {
        $this->config->runs($runs);

        return $this;
    }

    public function warmup(int $warmup): self
    {
        $this->config->warmup($warmup);

        return $this;
    }

    public function stable(): self
    {
        return $this->ultraPrecision()
            ->runs(50000)
            ->warmup(5000)
            ->isolateRuns()
            ->sleepBetweenRuns(0.001)
            ->enableStatistics()
            ->precisionDecimals(0)
        ;
    }

    public function microStable(): self
    {
        return $this->ultraPrecision()
            ->showMicroseconds()
            ->runs(25000)
            ->warmup(2500)
            ->isolateRuns()
            ->sleepBetweenRuns(0.0005)
            ->enableStatistics()
            ->precisionDecimals(3)
        ;
    }

    public function filterOutliers(bool $enable = true): self
    {
        $this->config->filterOutliers($enable);

        return $this;
    }

    public function outlierThreshold(float $threshold): self
    {
        $this->config->outlierThreshold($threshold);

        return $this;
    }

    public function sleepBetweenRuns(float $seconds): self
    {
        $this->config->sleepBetweenRuns($seconds);

        return $this;
    }

    public function enableMemoryTracking(bool $enable = true): self
    {
        $this->config->enableMemoryTracking($enable);

        return $this;
    }

    public function enableGarbageCollection(bool $enable = true): self
    {
        $this->config->enableGarbageCollection($enable);

        return $this;
    }

    public function silent(bool $silent = true): self
    {
        $this->config->silent($silent);

        return $this;
    }

    public function outputEnabled(bool $enabled = true): self
    {
        $this->config->outputEnabled($enabled);

        return $this;
    }

    public function enableStatistics(bool $enable = true): self
    {
        $this->config->enableStatistics($enable);

        return $this;
    }

    public function highPrecision(bool $enable = true): self
    {
        $this->config->highPrecision($enable);

        return $this;
    }

    public function isolateRuns(bool $enable = true): self
    {
        $this->config->isolateRuns($enable);

        return $this;
    }

    public function add(string $name, callable $callback): self
    {
        $this->benchmarks[$name] = $callback;

        return $this;
    }

    public function benchmarks(array $benchmarks): self
    {
        $this->benchmarks = array_merge($this->benchmarks, $benchmarks);

        return $this;
    }

    public function fromArray(array $options): self
    {
        $this->config = BenchmarkConfig::fromArray(array_merge([
            'output_enabled' => true,
        ], $options));

        return $this;
    }

    public function config(): BenchmarkConfig
    {
        return $this->config;
    }

    public function run(): array
    {
        if (empty($this->benchmarks)) {
            throw new InvalidArgumentException('No benchmarks added. Use add() or benchmarks() to add benchmarks.');
        }

        $results = [];
        $output = new BenchmarkOutput($this->config);

        if ($this->config->isOutputEnabled()) {
            $output->displayComparisonHeader();
        }

        foreach ($this->benchmarks as $name => $callback) {
            $config = clone $this->config;
            $config->silent(true);

            $runner = BenchmarkRunner::create($name, $config)
                ->callback($callback)
                ->run()
            ;
            $results[$name] = $runner;
        }

        if ($this->config->isOutputEnabled()) {
            $output->displayComparison($results);
        }

        return $results;
    }

    public function quick(): self
    {
        return $this->runs(3)->warmup(0);
    }

    public function thorough(): self
    {
        return $this->runs(10)->warmup(3)->enableStatistics();
    }

    public function memory(): self
    {
        return $this->enableMemoryTracking()->enableGarbageCollection();
    }

    public function ultraPrecision(bool $enable = true): self
    {
        $this->config->ultraPrecision($enable);

        return $this;
    }

    public function precisionDecimals(int $decimals): self
    {
        $this->config->precisionDecimals($decimals);

        return $this;
    }

    public function showNanoseconds(bool $enable = true): self
    {
        $this->config->showNanoseconds($enable);

        return $this;
    }

    public function showMicroseconds(bool $enable = true): self
    {
        $this->config->showMicroseconds($enable);

        return $this;
    }

    public function forceHrtime(bool $enable = true): self
    {
        $this->config->forceHrtime($enable);

        return $this;
    }

    public function precise(): self
    {
        return $this->ultraPrecision()
            ->runs(100)
            ->warmup(10)
            ->isolateRuns()
            ->precisionDecimals(6)
        ;
    }

    public function nanoPrecise(): self
    {
        return $this->ultraPrecision()
            ->showNanoseconds()
            ->runs(1000)
            ->warmup(50)
            ->isolateRuns()
        ;
    }

    public function microPrecise(): self
    {
        return $this->ultraPrecision()
            ->showMicroseconds()
            ->precisionDecimals(3)
            ->runs(500)
            ->warmup(25)
            ->isolateRuns()
        ;
    }

    public function getWinner(): ?BenchmarkRunner
    {
        $results = $this->run();
        if (empty($results)) {
            return null;
        }

        $fastest = null;
        $fastestTime = PHP_FLOAT_MAX;

        foreach ($results as $name => $benchmark) {
            $time = $benchmark->getAverageTime();
            if ($time < $fastestTime) {
                $fastestTime = $time;
                $fastest = $benchmark;
            }
        }

        return $fastest;
    }

    public function getSortedResults(): array
    {
        $results = $this->run();

        uasort(
            $results,
            fn ($a, $b) => $a->getAverageTime() <=> $b->getAverageTime()
        );

        return $results;
    }
}
