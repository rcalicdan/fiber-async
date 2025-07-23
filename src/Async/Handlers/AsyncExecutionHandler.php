<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Fiber;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
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
     * @param  callable  $asyncFunction  The function to make asynchronous
     * @return callable A function that returns a Promise when called
     */
    public function async(callable $asyncFunction): callable
    {
        return function (...$args) use ($asyncFunction) {
            return new Promise(function ($resolve, $reject) use ($asyncFunction, $args) {
                $fiber = new Fiber(function () use ($asyncFunction, $args, $resolve, $reject) {
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

    /**
     * Convert a synchronous function into an asynchronous version.
     *
     * This is an alias for async() but with a name that emphasizes the
     * conversion from synchronous to asynchronous execution.
     *
     * @param  callable  $syncFunction  The synchronous function to convert
     * @return callable A function that returns a Promise when called
     */
    public function asyncify(callable $syncFunction): callable
    {
        return function (...$args) use ($syncFunction) {
            return new Promise(function ($resolve, $reject) use ($syncFunction, $args) {
                $fiber = new Fiber(function () use ($syncFunction, $args, $resolve, $reject) {
                    try {
                        $result = $syncFunction(...$args);
                        $resolve($result);
                    } catch (Throwable $e) {
                        $reject($e);
                    }
                });

                EventLoop::getInstance()->addFiber($fiber);
            });
        };
    }

    /**
     * Create an async function that automatically awaits Promise results.
     *
     * This creates an async function that will automatically await any Promise
     * returned by the wrapped function, providing a more convenient API for
     * chaining async operations.
     *
     * @param  callable  $asyncFunction  The async function to wrap
     * @param  FiberContextHandler  $contextHandler  Handler for fiber context validation
     * @param  AwaitHandler  $awaitHandler  Handler for awaiting Promise results
     * @return callable A function that automatically awaits results
     */
    public function tryAsync(callable $asyncFunction, FiberContextHandler $contextHandler, AwaitHandler $awaitHandler): callable
    {
        return $this->async(function (...$args) use ($asyncFunction, $awaitHandler) {
            try {
                return $awaitHandler->await($asyncFunction(...$args));
            } catch (Throwable $e) {
                throw $e; // Re-throw to be caught by calling code
            }
        });
    }
}
