<?php

namespace Rcalicdan\FiberAsync\Benchmark;

use InvalidArgumentException;
use Rcalicdan\FiberAsync\Benchmark\Utils\BenchmarkAnalyzer;
use Rcalicdan\FiberAsync\Benchmark\Utils\BenchmarkExecutor;
use Rcalicdan\FiberAsync\Benchmark\Utils\BenchmarkOutput;

class BenchmarkRunner
{
    private static array $globalResults = [];

    private string $name;
    private BenchmarkConfig $config;
    private ?BenchmarkOutput $output = null;
    private $callback = null;

    public function __construct(string $name, ?BenchmarkConfig $config = null)
    {
        $this->name = $name;
        $this->config = $config ?? new BenchmarkConfig;
        $this->output = new BenchmarkOutput($this->config);
    }

    public static function create(string $name, ?BenchmarkConfig $config = null): self
    {
        return new self($name, $config);
    }

    public static function quick(callable $callback): float
    {
        $config = BenchmarkConfig::create()->runs(3)->warmup(0)->silent();

        return self::create('Quick Benchmark', $config)
            ->callback($callback)
            ->run()
            ->getAverageTime()
        ;
    }

    public static function memory(string $name, callable $callback): self
    {
        $config = BenchmarkConfig::create()->enableMemoryTracking();

        return self::create($name, $config)
            ->callback($callback)
            ->run()
        ;
    }

    public static function compareWith(): BenchmarkComparison
    {
        return new BenchmarkComparison;
    }

    public static function compare(array $benchmarks, array $options = []): array
    {
        return self::compareWith()
            ->fromArray($options)
            ->benchmarks($benchmarks)
            ->run()
        ;
    }

    public function callback(callable $callback): self
    {
        $this->callback = $callback;

        return $this;
    }

    public function config(): BenchmarkConfig
    {
        return $this->config;
    }

    public function run(): self
    {
        if ($this->callback === null) {
            throw new InvalidArgumentException('Callback must be set before running benchmark');
        }

        $executor = new BenchmarkExecutor($this->config);
        $results = $executor->execute($this->name, $this->callback);

        $analyzer = new BenchmarkAnalyzer($this->config);
        $processedResults = $analyzer->analyze($results);

        self::$globalResults[$this->name] = $processedResults;

        if ($this->config->isOutputEnabled()) {
            $this->output->displayResults($processedResults);
        }

        return $this;
    }

    public function getResults(): array
    {
        return self::$globalResults[$this->name] ?? [];
    }

    public function getAverageTime(): float
    {
        return $this->getResults()['summary']['avg_time_ms'] ?? 0.0;
    }

    public function getMedianTime(): float
    {
        return $this->getResults()['summary']['median_time_ms'] ?? 0.0;
    }

    public function getTotalTime(): float
    {
        return $this->getResults()['summary']['total_time_ms'] ?? 0.0;
    }

    public function getMemoryUsage(): array
    {
        return $this->getResults()['memory'] ?? [];
    }

    public function getStatistics(): array
    {
        return $this->getResults()['statistics'] ?? [];
    }

    public static function getAllResults(): array
    {
        return self::$globalResults;
    }

    public static function clearResults(): void
    {
        self::$globalResults = [];
    }

    public function summary(): self
    {
        if ($this->config->isOutputEnabled()) {
            $this->output->displayResults($this->getResults());
        }

        return $this;
    }
}
