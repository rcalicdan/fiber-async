<?php

namespace Rcalicdan\FiberAsync\Promise\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Handles Promise chaining operations and callback transformation.
 *
 * This handler manages the complex logic of Promise chaining, including
 * transforming values, handling nested Promises, and scheduling callback
 * execution. It's responsible for the fluent API that allows .then().catch() chains.
 */
final readonly class ChainHandler
{
    /**
     * Create a handler function for 'then' operations in Promise chains.
     *
     * This creates a function that will be called when a Promise resolves.
     * It handles value transformation, nested Promise resolution, and error
     * propagation in the chain.
     *
     * @param callable|null $onFulfilled Callback to transform the resolved value.
     * @param callable $resolve Function to resolve the chained Promise.
     * @param callable $reject Function to reject the chained Promise.
     * @return callable The handler function for the then operation.
     */
    public function createThenHandler(?callable $onFulfilled, callable $resolve, callable $reject): callable
    {
        return static function ($value) use ($onFulfilled, $resolve, $reject) {
            if ($onFulfilled !== null) {
                try {
                    $result = $onFulfilled($value);
                    if ($result instanceof PromiseInterface) {
                        // If the handler returns another promise, pipe its result.
                        $result->then($resolve, $reject);
                    } else {
                        // Otherwise, resolve with the transformed value.
                        $resolve($result);
                    }
                } catch (Throwable $e) {
                    $reject($e);
                }
            } else {
                // If there's no fulfillment handler, pass the value through.
                $resolve($value);
            }
        };
    }

    /**
     * Create a handler function for 'catch' operations in Promise chains.
     *
     * This creates a function that will be called when a Promise rejects.
     * It handles error recovery, value transformation, and continued chaining
     * after error handling.
     *
     * @param callable|null $onRejected Callback to handle the rejection reason.
     * @param callable $resolve Function to resolve the chained Promise.
     * @param callable $reject Function to reject the chained Promise.
     * @return callable The handler function for the catch operation.
     */
    public function createCatchHandler(?callable $onRejected, callable $resolve, callable $reject): callable
    {
        return static function ($reason) use ($onRejected, $resolve, $reject) {
            if ($onRejected !== null) {
                try {
                    $result = $onRejected($reason);
                    if ($result instanceof PromiseInterface) {
                        // If the handler returns another promise, pipe its result.
                        $result->then($resolve, $reject);
                    } else {
                        $resolve($result);
                    }
                } catch (Throwable $e) {
                    $reject($e);
                }
            } else {
                // If there's no rejection handler, pass the rejection through.
                $reject($reason);
            }
        };
    }

    /**
     * Schedule a handler function to execute on the next event loop tick.
     *
     * This ensures that Promise callbacks are executed asynchronously,
     * maintaining the proper execution order and preventing stack overflow
     * in long Promise chains.
     *
     * @param callable $handler The handler function to schedule.
     * @return void
     */
    public function scheduleHandler(callable $handler): void
    {
        EventLoop::getInstance()->nextTick($handler);
    }
}