<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Throwable;

final readonly class LoopExecutionHandler
{
    private AsyncOperations $asyncOps;

    public function __construct(AsyncOperations $asyncOps)
    {
        $this->asyncOps = $asyncOps;
    }

    public function run(callable|PromiseInterface $asyncOperation): mixed
    {
        $loop = AsyncEventLoop::getInstance();
        $result = null;
        $error = null;

        $promise = $this->createPromiseFromOperation($asyncOperation);

        $promise
            ->then(function ($value) use (&$result) {
                $result = $value;
            })
            ->catch(function ($reason) use (&$error) {
                $error = $reason;
            });

        $loop->run();

        if ($error !== null) {
            throw $error instanceof Throwable ? $error : new Exception((string) $error);
        }

        return $result;
    }

    public function createPromiseFromOperation(callable|PromiseInterface $operation): PromiseInterface
    {
        return is_callable($operation)
            ? $this->asyncOps->async($operation)()
            : $operation;
    }
}