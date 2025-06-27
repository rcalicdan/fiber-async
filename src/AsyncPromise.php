<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\AsyncPromise\StateHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncPromise\CallbackHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncPromise\ExecutorHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncPromise\ChainHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncPromise\ResolutionHandler;

class AsyncPromise implements PromiseInterface
{
    private StateHandler $stateHandler;
    private CallbackHandler $callbackHandler;
    private ExecutorHandler $executorHandler;
    private ChainHandler $chainHandler;
    private ResolutionHandler $resolutionHandler;

    public function __construct(?callable $executor = null)
    {
        $this->stateHandler = new StateHandler;
        $this->callbackHandler = new CallbackHandler;
        $this->executorHandler = new ExecutorHandler;
        $this->chainHandler = new ChainHandler;
        $this->resolutionHandler = new ResolutionHandler(
            $this->stateHandler,
            $this->callbackHandler
        );

        $this->executorHandler->executeExecutor(
            $executor,
            fn($value) => $this->resolve($value),
            fn($reason) => $this->reject($reason)
        );
    }

    public function resolve(mixed $value): void
    {
        $this->resolutionHandler->handleResolve($value);
    }

    public function reject(mixed $reason): void
    {
        $this->resolutionHandler->handleReject($reason);
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        return new self(function ($resolve, $reject) use ($onFulfilled, $onRejected) {
            $handleResolve = $this->chainHandler->createThenHandler($onFulfilled, $resolve, $reject);
            $handleReject = $this->chainHandler->createCatchHandler($onRejected, $resolve, $reject);

            if ($this->stateHandler->isResolved()) {
                $this->chainHandler->scheduleHandler(fn() => $handleResolve($this->stateHandler->getValue()));
            } elseif ($this->stateHandler->isRejected()) {
                $this->chainHandler->scheduleHandler(fn() => $handleReject($this->stateHandler->getReason()));
            } else {
                $this->callbackHandler->addThenCallback($handleResolve);
                $this->callbackHandler->addCatchCallback($handleReject);
            }
        });
    }

    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFinally): PromiseInterface
    {
        $this->callbackHandler->addFinallyCallback($onFinally);
        return $this;
    }

    public function isResolved(): bool
    {
        return $this->stateHandler->isResolved();
    }

    public function isRejected(): bool
    {
        return $this->stateHandler->isRejected();
    }

    public function isPending(): bool
    {
        return $this->stateHandler->isPending();
    }

    public function getValue(): mixed
    {
        return $this->stateHandler->getValue();
    }

    public function getReason(): mixed
    {
        return $this->stateHandler->getReason();
    }
}
