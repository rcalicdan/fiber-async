<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Exception;
use Throwable;

class LoopExecutionHandler
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
        $completed = false;

        $promise = is_callable($asyncOperation)
            ? $this->asyncOps->async($asyncOperation)()
            : $asyncOperation;

        $promise
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            })
            ->catch(function ($reason) use (&$error, &$completed) {
                $error = $reason;
                $completed = true;
            });

        while (!$completed && !$loop->isIdle()) {
            $loop->run();
            if ($completed) {
                break;
            }
            usleep(1000); // 1ms
        }

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