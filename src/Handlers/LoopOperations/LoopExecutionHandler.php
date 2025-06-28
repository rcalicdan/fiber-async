<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncOperations;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Throwable;

/**
 * Handles execution of async operations within the event loop.
 *
 * This class provides the core functionality for running async operations
 * by integrating them with the event loop and handling promise resolution.
 */
final readonly class LoopExecutionHandler
{
    /**
     * Async operations instance for creating and managing promises.
     */
    private AsyncOperations $asyncOps;

    /**
     * Initialize the loop execution handler.
     *
     * @param  AsyncOperations  $asyncOps  Async operations instance
     */
    public function __construct(AsyncOperations $asyncOps)
    {
        $this->asyncOps = $asyncOps;
    }

    /**
     * Run an async operation and return its result synchronously.
     *
     * Executes the operation within the event loop, handling promise
     * resolution and error cases. Blocks until the operation completes
     * or the loop becomes idle.
     *
     * @param  callable|PromiseInterface  $asyncOperation  The operation to execute
     * @return mixed The result of the async operation
     *
     * @throws Exception|Throwable If the operation fails
     */
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
            })
        ;

        while (! $completed && ! $loop->isIdle()) {
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

    /**
     * Create a promise from a callable or existing promise.
     *
     * Converts callable operations to promises or returns existing
     * promises unchanged for consistent handling.
     *
     * @param  callable|PromiseInterface  $operation  The operation to convert
     * @return PromiseInterface The resulting promise
     */
    public function createPromiseFromOperation(callable|PromiseInterface $operation): PromiseInterface
    {
        return is_callable($operation)
            ? $this->asyncOps->async($operation)()
            : $operation;
    }
}
