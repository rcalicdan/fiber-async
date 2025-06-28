<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Fiber;
use RuntimeException;

final readonly class FiberContextHandler
{
    public function inFiber(): bool
    {
        return Fiber::getCurrent() !== null;
    }

    public function validateFiberContext(): void
    {
        if (!$this->inFiber()) {
            throw new RuntimeException('Operation can only be used inside a Fiber context');
        }
    }
}