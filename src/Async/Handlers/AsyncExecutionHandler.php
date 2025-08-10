<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Fiber;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use Throwable;

/**
 * Handles the execution of asynchronous operations using PHP Fibers.
 *
 * This handler provides utilities to convert regular functions into async functions,
 * manage fiber execution, and handle error propagation in asynchronous contexts.
 * It's the core component for creating and managing async operations.
 */
final readonly class AsyncExecutionHandler
{
    /**
     * Convert a function into an asynchronous version that returns a Promise.
     *
     * The returned function, when called, will execute the original function
     * inside a Fiber and return a Promise that resolves with the result.
     *
     * @param  callable  $asyncFunction  The function to make asynchronous.
     * @return callable(): PromiseInterface<mixed> A function that returns a Promise when called.
     */
    public function async(callable $asyncFunction): callable
    {
        return function (...$args) use ($asyncFunction): PromiseInterface {
            return new Promise(function (callable $resolve, callable $reject) use ($asyncFunction, $args) {
                $fiber = new Fiber(function () use ($asyncFunction, $args, $resolve, $reject): void {
                    try {
                        $result = $asyncFunction(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                EventLoop::getInstance()->addFiber($fiber);
            });
        };
    }
}
