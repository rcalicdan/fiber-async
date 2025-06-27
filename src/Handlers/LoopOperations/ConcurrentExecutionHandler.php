<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncOperations;

class ConcurrentExecutionHandler
{
    private AsyncOperations $asyncOps;
    private LoopExecutionHandler $executionHandler;

    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    public function runAll(array $asyncOperations): array
    {
        return $this->executionHandler->run(function () use ($asyncOperations) {
            $promises = [];

            foreach ($asyncOperations as $key => $operation) {
                $promises[$key] = $this->executionHandler->createPromiseFromOperation($operation);
            }

            return $this->asyncOps->await($this->asyncOps->all($promises));
        });
    }

    public function runConcurrent(array $asyncOperations, int $concurrency = 10): array
    {
        return $this->executionHandler->run(function () use ($asyncOperations, $concurrency) {
            return $this->asyncOps->await($this->asyncOps->concurrent($asyncOperations, $concurrency));
        });
    }
}