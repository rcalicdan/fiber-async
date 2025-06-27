<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncPromise;

use Rcalicdan\FiberAsync\AsyncEventLoop;

class ResolutionHandler
{
    private StateHandler $stateHandler;
    private CallbackHandler $callbackHandler;

    public function __construct(StateHandler $stateHandler, CallbackHandler $callbackHandler)
    {
        $this->stateHandler = $stateHandler;
        $this->callbackHandler = $callbackHandler;
    }

    public function handleResolve(mixed $value): void
    {
        $this->stateHandler->resolve($value);

        AsyncEventLoop::getInstance()->nextTick(function () use ($value) {
            $this->callbackHandler->executeThenCallbacks($value);
            $this->callbackHandler->executeFinallyCallbacks();
        });
    }

    public function handleReject(mixed $reason): void
    {
        $this->stateHandler->reject($reason);

        AsyncEventLoop::getInstance()->nextTick(function () {
            $this->callbackHandler->executeCatchCallbacks($this->stateHandler->getReason());
            $this->callbackHandler->executeFinallyCallbacks();
        });
    }
}