<?php

namespace Rcalicdan\FiberAsync\Bridges;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use Illuminate\Http\Client\Factory as LaravelHttpFactory;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

class HttpClientBridge
{
    private static ?HttpClientBridge $instance = null;
    private ?GuzzleClient $guzzleClient = null;
    private ?LaravelHttpFactory $laravelHttp = null;

    public static function getInstance(): HttpClientBridge
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Make Guzzle requests asynchronous
     */
    public function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        if ($this->guzzleClient === null) {
            $this->guzzleClient = new GuzzleClient;
        }

        return new AsyncPromise(function ($resolve, $reject) use ($method, $url, $options) {
            $fiber = new \Fiber(function () use ($method, $url, $options, $resolve, $reject) {
                try {
                    // Use Guzzle's async capabilities
                    $guzzlePromise = $this->guzzleClient->requestAsync($method, $url, $options);

                    // Convert Guzzle promise to our promise
                    $this->bridgeGuzzlePromise($guzzlePromise, $resolve, $reject);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });

            AsyncEventLoop::getInstance()->addFiber($fiber);
        });
    }

    /**
     * Make Laravel HTTP client requests asynchronous
     */
    public function laravel(): LaravelHttpBridge
    {
        if ($this->laravelHttp === null) {
            $this->laravelHttp = new LaravelHttpFactory;
        }

        return new LaravelHttpBridge($this->laravelHttp);
    }

    /**
     * Wrap any synchronous HTTP call to make it async
     */
    public function wrap(callable $httpCall): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($httpCall) {
            $fiber = new \Fiber(function () use ($httpCall, $resolve, $reject) {
                try {
                    $result = $httpCall();
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });

            AsyncEventLoop::getInstance()->addFiber($fiber);
        });
    }

    private function bridgeGuzzlePromise(GuzzlePromiseInterface $guzzlePromise, callable $resolve, callable $reject): void
    {
        $guzzlePromise->then(
            function ($response) use ($resolve) {
                AsyncEventLoop::getInstance()->nextTick(fn () => $resolve($response));
            },
            function ($reason) use ($reject) {
                AsyncEventLoop::getInstance()->nextTick(fn () => $reject($reason));
            }
        );
    }
}

class LaravelHttpBridge
{
    private LaravelHttpFactory $http;

    public function __construct(LaravelHttpFactory $http)
    {
        $this->http = $http;
    }

    public function get(string $url, array $query = []): PromiseInterface
    {
        return $this->makeRequest('GET', $url, ['query' => $query]);
    }

    public function post(string $url, array $data = []): PromiseInterface
    {
        return $this->makeRequest('POST', $url, ['json' => $data]);
    }

    public function put(string $url, array $data = []): PromiseInterface
    {
        return $this->makeRequest('PUT', $url, ['json' => $data]);
    }

    public function delete(string $url): PromiseInterface
    {
        return $this->makeRequest('DELETE', $url);
    }

    private function makeRequest(string $method, string $url, array $options = []): PromiseInterface
    {
        return new AsyncPromise(function ($resolve, $reject) use ($method, $url, $options) {
            $fiber = new \Fiber(function () use ($method, $url, $options, $resolve, $reject) {
                try {
                    $response = $this->http->send($method, $url, $options);
                    $resolve($response);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });

            AsyncEventLoop::getInstance()->addFiber($fiber);
        });
    }
}
