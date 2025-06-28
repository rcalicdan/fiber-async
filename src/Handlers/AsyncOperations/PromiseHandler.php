<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles creation and basic operations on Promise instances.
 *
 * This handler provides factory methods for creating Promises in various
 * states and basic Promise utilities. It's used for creating resolved,
 * rejected, or empty Promises as needed by the async system.
 */
final readonly class PromiseHandler
{
    /**
     * Create a Promise that immediately resolves with the given value.
     *
     * This is useful for converting synchronous values into Promise form
     * or for creating resolved Promises in async chains.
     *
     * @param  mixed  $value  The value to resolve the Promise with
     * @return PromiseInterface A Promise that resolves with the given value
     */
    public function resolve(mixed $value): PromiseInterface
    {
        $promise = new AsyncPromise;
        $promise->resolve($value);

        return $promise;
    }

    /**
     * Create a Promise that immediately rejects with the given reason.
     *
     * This is useful for converting errors into Promise form or for
     * creating rejected Promises in async chains.
     *
     * @param  mixed  $reason  The reason to reject the Promise with
     * @return PromiseInterface A Promise that rejects with the given reason
     */
    public function reject(mixed $reason): PromiseInterface
    {
        $promise = new AsyncPromise;
        $promise->reject($reason);

        return $promise;
    }

    /**
     * Create an empty Promise that can be resolved or rejected later.
     *
     * This creates a Promise in pending state that can be manually
     * resolved or rejected at a later time.
     *
     * @return PromiseInterface An empty Promise in pending state
     */
    public function createEmpty(): PromiseInterface
    {
        return new AsyncPromise;
    }
}
