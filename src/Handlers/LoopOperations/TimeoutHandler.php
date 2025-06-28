<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Exception;

final readonly class TimeoutHandler
{
    private AsyncOperations $asyncOps;
    private LoopExecutionHandler $executionHandler;

    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    public function runWithTimeout(callable|PromiseInterface $asyncOperation, float $timeout): mixed
    {
        return $this->executionHandler->run(function () use ($asyncOperation, $timeout) {
            $promise = $this->executionHandler->createPromiseFromOperation($asyncOperation);

            $timeoutPromise = $this->createTimeoutPromise($timeout);

            return $this->asyncOps->await($this->asyncOps->race([$promise, $timeoutPromise]));
        });
    }

    private function createTimeoutPromise(float $timeout): PromiseInterface
    {
        return $this->asyncOps->async(function () use ($timeout) {
            $this->asyncOps->await($this->asyncOps->delay($timeout));
            throw new Exception("Operation timed out after {$timeout} seconds");
        })();
    }
}