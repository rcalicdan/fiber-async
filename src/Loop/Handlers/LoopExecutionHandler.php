<?php

namespace Rcalicdan\FiberAsync\Loop\Handlers;

use Exception;
use Rcalicdan\FiberAsync\Async\AsyncOperations;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use Throwable;

final class LoopExecutionHandler
{
    private AsyncOperations $asyncOps;
    private static bool $isRunning = false;

    public function __construct(AsyncOperations $asyncOps)
    {
        $this->asyncOps = $asyncOps;
    }

    /**
     * @param callable|PromiseInterface<mixed> $asyncOperation
     * @return mixed
     */
    public function run(callable|PromiseInterface $asyncOperation): mixed
    {
        if (self::$isRunning) {
            throw new RuntimeException('Cannot call run() while already running. Use await() instead.');
        }

        try {
            self::$isRunning = true;
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

            while (!$completed) {
                EventLoop::getInstance()->run();
                time_nanosleep(0, 100); //sleep to prevent busy wait
            }

            if ($error !== null) {
                throw $error instanceof Throwable ? $error : new Exception($this->safeStringCast($error));
            }

            return $result;
        } finally {
            self::$isRunning = false;
            EventLoop::reset();
        }
    }

    /**
     * @param callable|PromiseInterface<mixed> $operation
     * @return PromiseInterface<mixed>
     */
    public function createPromiseFromOperation(callable|PromiseInterface $operation): PromiseInterface
    {
        return is_callable($operation)
            ? $this->asyncOps->async($operation)()
            : $operation;
    }

    /**
     * Safely convert mixed value to string for error messages
     */
    private function safeStringCast(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_null($value) => 'null',
            is_scalar($value) => (string) $value,
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_array($value) => 'Array: ' . json_encode($value),
            is_object($value) => 'Object: ' . get_class($value),
            default => 'Unknown error type: ' . gettype($value)
        };
    }
}
