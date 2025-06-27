<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\AsyncEventLoop;

class ChainHandler
{
    public function createThenHandler(?callable $onFulfilled, callable $resolve, callable $reject): callable
    {
        return function ($value) use ($onFulfilled, $resolve, $reject) {
            if ($onFulfilled) {
                try {
                    $result = $onFulfilled($value);
                    if ($result instanceof PromiseInterface) {
                        $result->then($resolve, $reject);
                    } else {
                        $resolve($result);
                    }
                } catch (\Throwable $e) {
                    $reject($e);
                }
            } else {
                $resolve($value);
            }
        };
    }

    public function createCatchHandler(?callable $onRejected, callable $resolve, callable $reject): callable
    {
        return function ($reason) use ($onRejected, $resolve, $reject) {
            if ($onRejected) {
                try {
                    $result = $onRejected($reason);
                    if ($result instanceof PromiseInterface) {
                        $result->then($resolve, $reject);
                    } else {
                        $resolve($result);
                    }
                } catch (\Throwable $e) {
                    $reject($e);
                }
            } else {
                $reject($reason);
            }
        };
    }

    public function scheduleHandler(callable $handler): void
    {
        AsyncEventLoop::getInstance()->nextTick($handler);
    }
}
