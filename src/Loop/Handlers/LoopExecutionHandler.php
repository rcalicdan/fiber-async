<?php

namespace Rcalicdan\FiberAsync\Loop\Handlers;

use Exception;
use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
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
        try {
            $loop = EventLoop::getInstance();
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
                })
            ;

            while (! $completed) {
                $loop->run();

                if (! $completed) {
                    usleep(100);
                }
            }

            if ($error !== null) {
                throw $error instanceof Throwable ? $error : new Exception((string) $error);
            }

            return $result;
        } finally {
            EventLoop::reset();
        }
    }

    public function createPromiseFromOperation(callable|PromiseInterface $operation): PromiseInterface
    {
        return is_callable($operation)
            ? $this->asyncOps->async($operation)()
            : $operation;
    }
}
