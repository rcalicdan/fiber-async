<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class BenchmarkHandler
{
    private LoopExecutionHandler $executionHandler;

    public function __construct(LoopExecutionHandler $executionHandler)
    {
        $this->executionHandler = $executionHandler;
    }

    public function benchmark(callable|PromiseInterface $asyncOperation): array
    {
        $start = microtime(true);
        $result = $this->executionHandler->run($asyncOperation);
        $duration = microtime(true) - $start;

        return [
            'result' => $result,
            'duration' => $duration,
            'duration_ms' => round($duration * 1000, 2),
        ];
    }

    public function formatBenchmarkResult(array $benchmarkResult): string
    {
        return sprintf(
            "Operation completed in %.2fms (%.6fs)",
            $benchmarkResult['duration_ms'],
            $benchmarkResult['duration']
        );
    }
}