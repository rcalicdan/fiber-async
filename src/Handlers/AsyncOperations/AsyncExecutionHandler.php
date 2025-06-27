<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Fiber;
use Throwable;

class AsyncExecutionHandler
{
    public function async(callable $asyncFunction): callable
    {
        return function (...$args) use ($asyncFunction) {
            return new AsyncPromise(function ($resolve, $reject) use ($asyncFunction, $args) {
                $fiber = new Fiber(function () use ($asyncFunction, $args, $resolve, $reject) {
                    try {
                        $result = $asyncFunction(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                AsyncEventLoop::getInstance()->addFiber($fiber);
            });
        };
    }

    public function asyncify(callable $syncFunction): callable
    {
        return function (...$args) use ($syncFunction) {
            return new AsyncPromise(function ($resolve, $reject) use ($syncFunction, $args) {
                $fiber = new Fiber(function () use ($syncFunction, $args, $resolve, $reject) {
                    try {
                        $result = $syncFunction(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                AsyncEventLoop::getInstance()->addFiber($fiber);
            });
        };
    }

    public function tryAsync(callable $asyncFunction, FiberContextHandler $contextHandler, AwaitHandler $awaitHandler): callable
    {
        return $this->async(function (...$args) use ($asyncFunction, $contextHandler, $awaitHandler) {
            try {
                return $awaitHandler->await($asyncFunction(...$args));
            } catch (Throwable $e) {
                throw $e; // Re-throw to be caught by calling code
            }
        });
    }
}