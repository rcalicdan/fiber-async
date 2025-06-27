<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class PromiseHandler
{
    public function resolve(mixed $value): PromiseInterface
    {
        $promise = new AsyncPromise();
        $promise->resolve($value);
        return $promise;
    }

    public function reject(mixed $reason): PromiseInterface
    {
        $promise = new AsyncPromise();
        $promise->reject($reason);
        return $promise;
    }

    public function createEmpty(): PromiseInterface
    {
        return new AsyncPromise();
    }
}