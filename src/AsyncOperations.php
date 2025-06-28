<?php

namespace Rcalicdan\FiberAsync;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\AsyncExecutionHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\AwaitHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\ConcurrencyHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\FiberContextHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\HttpHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\PromiseCollectionHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\PromiseHandler;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\TimerHandler;

class AsyncOperations
{
    private FiberContextHandler $contextHandler;
    private PromiseHandler $promiseHandler;
    private AsyncExecutionHandler $executionHandler;
    private AwaitHandler $awaitHandler;
    private TimerHandler $timerHandler;
    private HttpHandler $httpHandler;
    private PromiseCollectionHandler $collectionHandler;
    private ConcurrencyHandler $concurrencyHandler;

    public function __construct()
    {
        $this->contextHandler = new FiberContextHandler;
        $this->promiseHandler = new PromiseHandler;
        $this->executionHandler = new AsyncExecutionHandler;
        $this->awaitHandler = new AwaitHandler($this->contextHandler);
        $this->timerHandler = new TimerHandler;
        $this->httpHandler = new HttpHandler;
        $this->collectionHandler = new PromiseCollectionHandler;
        $this->concurrencyHandler = new ConcurrencyHandler($this->executionHandler);
    }

    public function inFiber(): bool
    {
        return $this->contextHandler->inFiber();
    }

    public function resolve(mixed $value): PromiseInterface
    {
        return $this->promiseHandler->resolve($value);
    }

    public function reject(mixed $reason): PromiseInterface
    {
        return $this->promiseHandler->reject($reason);
    }

    public function async(callable $asyncFunction): callable
    {
        return $this->executionHandler->async($asyncFunction);
    }

    public function asyncify(callable $syncFunction): callable
    {
        return $this->executionHandler->asyncify($syncFunction);
    }

    public function tryAsync(callable $asyncFunction): callable
    {
        return $this->executionHandler->tryAsync($asyncFunction, $this->contextHandler, $this->awaitHandler);
    }

    public function await(PromiseInterface $promise): mixed
    {
        return $this->awaitHandler->await($promise);
    }

    public function delay(float $seconds): PromiseInterface
    {
        return $this->timerHandler->delay($seconds);
    }

    public function fetch(string $url, array $options = []): PromiseInterface
    {
        return $this->httpHandler->fetch($url, $options);
    }

    public function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return $this->httpHandler->guzzle($method, $url, $options);
    }

    public function http()
    {
        return $this->httpHandler->http();
    }

    public function wrapSync(callable $syncCall): PromiseInterface
    {
        return $this->httpHandler->wrapSync($syncCall);
    }

    public function all(array $promises): PromiseInterface
    {
        return $this->collectionHandler->all($promises);
    }

    public function race(array $promises): PromiseInterface
    {
        return $this->collectionHandler->race($promises);
    }

    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return $this->concurrencyHandler->concurrent($tasks, $concurrency);
    }
}
