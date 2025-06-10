<?php

namespace TrueAsync\Interfaces;

interface PromiseInterface
{
    public function resolve(mixed $value): void;
    public function reject(mixed $reason): void;
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;
    public function catch(callable $onRejected): PromiseInterface;
    public function finally(callable $onFinally): PromiseInterface;
    public function isResolved(): bool;
    public function isRejected(): bool;
    public function isPending(): bool;
    public function getValue(): mixed;
    public function getReason(): mixed;
}