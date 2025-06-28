<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncOperations;

final readonly class HttpExecutionHandler
{
    private AsyncOperations $asyncOps;
    private LoopExecutionHandler $executionHandler;

    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    public function quickFetch(string $url, array $options = []): array
    {
        return $this->executionHandler->run(function () use ($url, $options) {
            return $this->asyncOps->await($this->asyncOps->fetch($url, $options));
        });
    }
}