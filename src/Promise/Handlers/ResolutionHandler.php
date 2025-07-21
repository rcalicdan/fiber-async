<?php

namespace Rcalicdan\FiberAsync\Promise\Handlers;

use Rcalicdan\FiberAsync\EventLoop\EventLoop;

/**
 * Handles Promise resolution and rejection operations.
 *
 * This handler manages the process of settling a Promise (resolving or rejecting)
 * and coordinates the execution of associated callbacks. It ensures that callbacks
 * are executed asynchronously and in the correct order.
 */
final readonly class ResolutionHandler
{
    private StateHandler $stateHandler;
    private CallbackHandler $callbackHandler;

    /**
     * @param  StateHandler  $stateHandler  Handler for managing Promise state
     * @param  CallbackHandler  $callbackHandler  Handler for managing Promise callbacks
     */
    public function __construct(StateHandler $stateHandler, CallbackHandler $callbackHandler)
    {
        $this->stateHandler = $stateHandler;
        $this->callbackHandler = $callbackHandler;
    }

    /**
     * Handle Promise resolution with a value.
     *
     * This method settles the Promise in a resolved state and schedules
     * the execution of then and finally callbacks on the next event loop tick.
     *
     * @param  mixed  $value  The value to resolve the Promise with
     */
    public function handleResolve(mixed $value): void
    {
        $this->stateHandler->resolve($value);

        EventLoop::getInstance()->nextTick(function () use ($value) {
            $this->callbackHandler->executeThenCallbacks($value);
            $this->callbackHandler->executeFinallyCallbacks();
        });
    }

    /**
     * Handle Promise rejection with a reason.
     *
     * This method settles the Promise in a rejected state and schedules
     * the execution of catch and finally callbacks on the next event loop tick.
     *
     * @param  mixed  $reason  The reason to reject the Promise with
     */
    public function handleReject(mixed $reason): void
    {
        $this->stateHandler->reject($reason);

        EventLoop::getInstance()->nextTick(function () {
            $this->callbackHandler->executeCatchCallbacks($this->stateHandler->getReason());
            $this->callbackHandler->executeFinallyCallbacks();
        });
    }
}
