<?php

namespace Rcalicdan\FiberAsync\Contracts;

interface AsyncPromiseInterface extends PromiseInterface
{
    public function __construct(?callable $executor = null);
    public function isResolved(): bool;
    public function isRejected(): bool;
    public function isPending(): bool;
    public function getValue(): mixed;
    public function getReason(): mixed;
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;
    public function catch(callable $onRejected): PromiseInterface;
    public function finally(callable $onFinally): PromiseInterface;
}
