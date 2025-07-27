<?php

namespace Rcalicdan\FiberAsync\Async\Handlers;

use Exception;
use Fiber;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Throwable;

/**
 * Handles awaiting Promise resolution within Fiber contexts.
 *
 * This handler provides the core await functionality that allows synchronous-style
 * code to wait for asynchronous operations to complete. It works by suspending
 * the current Fiber until the Promise resolves or rejects.
 */
final readonly class AwaitHandler
{
    private FiberContextHandler $contextHandler;

    /**
     * @param  FiberContextHandler  $contextHandler  Handler for validating fiber context
     */
    public function __construct(FiberContextHandler $contextHandler)
    {
        $this->contextHandler = $contextHandler;
    }

    /**
     * Wait for a Promise to resolve and return its value.
     *
     * This method suspends the current Fiber until the Promise is settled.
     * If the Promise resolves, returns the resolved value.
     * If the Promise rejects, throws the rejection reason as an exception.
     *
     * @template T The type of the resolved value of the promise.
     *
     * @param  PromiseInterface<T>  $promise  The promise to await.
     * @return T The resolved value of the promise.
     *
     * @throws Exception|Throwable If the Promise is rejected
     * @throws \RuntimeException If not called from within a Fiber context
     */
    public function await(PromiseInterface $promise): mixed
    {
        $this->contextHandler->validateFiberContext('await() can only be used inside a Fiber context, make sure it is inside async() or run() inside a callable');

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
            })
        ;

        // Suspend the fiber until the promise completes
        while (! $completed) {
            Fiber::suspend();
        }

        if ($error !== null) {
            throw $error instanceof Throwable ? $error : new Exception((string) $error);
        }

        return $result;
    }
}
