<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncOperations;

final readonly class TaskExecutionHandler
{
    private AsyncOperations $asyncOps;
    private LoopExecutionHandler $executionHandler;

    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    public function task(callable $asyncFunction): mixed
    {
        return $this->executionHandler->run($this->asyncOps->async($asyncFunction)());
    }

    public function asyncSleep(float $seconds): void
    {
        $this->executionHandler->run(function () use ($seconds) {
            $this->asyncOps->await($this->asyncOps->delay($seconds));
        });
    }
}