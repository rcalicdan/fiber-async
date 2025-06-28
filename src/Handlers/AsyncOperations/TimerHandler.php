<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

final readonly class TimerHandler
{
    public function delay(float $seconds): PromiseInterface
    {
        return new AsyncPromise(function ($resolve) use ($seconds) {
            AsyncEventLoop::getInstance()->addTimer($seconds, function () use ($resolve) {
                $resolve(null);
            });
        });
    }
}