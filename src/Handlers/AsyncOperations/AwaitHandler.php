<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Fiber;
use Exception;
use Throwable;

class AwaitHandler
{
    private FiberContextHandler $contextHandler;

    public function __construct(FiberContextHandler $contextHandler)
    {
        $this->contextHandler = $contextHandler;
    }

    public function await(PromiseInterface $promise): mixed
    {
        $this->contextHandler->validateFiberContext();

        $result = null;
        $error = null;
        $completed = false;

        $promise
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            })
            ->catch(function ($reason) use (&$error, &$completed) {
                $error = $reason;
                $completed = true;
            });

        // Suspend the fiber until the promise completes
        while (!$completed) {
            Fiber::suspend();
        }

        if ($error !== null) {
            throw $error instanceof Throwable ? $error : new Exception((string) $error);
        }

        return $result;
    }
}