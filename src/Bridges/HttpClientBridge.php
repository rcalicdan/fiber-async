<?php

namespace Rcalicdan\FiberAsync\Bridges;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromiseInterface;
use Illuminate\Http\Client\Factory as LaravelHttpFactory;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Bridge for integrating popular HTTP clients with the async fiber system.
 *
 * This bridge provides seamless integration between synchronous HTTP clients
 * (like Guzzle and Laravel's HTTP client) and the fiber-based async system.
 * It converts blocking HTTP operations into non-blocking async operations
 * that work within the event loop architecture.
 *
 * The bridge handles the complexity of converting between different promise
 * implementations and ensures proper event loop integration for all HTTP
 * operations, maintaining compatibility with existing HTTP client APIs.
 */
class HttpClientBridge
{
    /**
     * @var HttpClientBridge|null Singleton instance for consistent HTTP client management
     */
    private static ?HttpClientBridge $instance = null;

    /**
     * @var GuzzleClient|null Cached Guzzle HTTP client instance
     */
    private ?GuzzleClient $guzzleClient = null;

    /**
     * @var LaravelHttpFactory|null Cached Laravel HTTP factory instance
     */
    private ?LaravelHttpFactory $laravelHttp = null;

    /**
     * Get the singleton instance of the HTTP client bridge.
     *
     * Ensures consistent HTTP client management across the application
     * and prevents multiple instances from interfering with each other.
     *
     * @return HttpClientBridge The singleton bridge instance
     */
    public static function getInstance(): HttpClientBridge
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Make Guzzle HTTP requests asynchronous and fiber-compatible.
     *
     * Converts Guzzle's promise-based async requests into fiber-compatible
     * promises that integrate with the event loop. This allows Guzzle requests
     * to be used with await() and other async operations without blocking
     * the event loop or interfering with concurrent operations.
     *
     * The method preserves all Guzzle features and options while adding
     * proper fiber context management and event loop integration.
     *
     * @param  string  $method  HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param  string  $url  The URL to request
     * @param  array  $options  Guzzle-specific request options (headers, timeout, etc.)
     * @return PromiseInterface A fiber-compatible promise that resolves with the Guzzle response
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
     * Get a Laravel HTTP client bridge for async operations.
     *
     * Creates a bridge that converts Laravel's HTTP client into async operations
     * compatible with the fiber system. The returned bridge maintains Laravel's
     * familiar API while adding async capabilities and event loop integration.
     *
     * This allows existing Laravel HTTP code to be easily converted to async
     * operations without changing the method signatures or expected behavior.
     *
     * @return LaravelHttpBridge A bridge instance for Laravel HTTP operations
     */
    public function laravel(): LaravelHttpBridge
    {
        if ($this->laravelHttp === null) {
            $this->laravelHttp = new LaravelHttpFactory;
        }

        return new LaravelHttpBridge($this->laravelHttp);
    }

    /**
     * Wrap any synchronous HTTP call to make it async and fiber-compatible.
     *
     * Takes any callable that performs HTTP operations and wraps it in a fiber
     * context, making it non-blocking and compatible with the async event loop.
     * This is useful for integrating third-party HTTP libraries or custom
     * HTTP implementations that don't natively support async operations.
     *
     * The wrapped operation will not block other concurrent operations and
     * can be used with await() and other async control flow methods.
     *
     * @param  callable  $httpCall  The synchronous HTTP operation to wrap
     * @return PromiseInterface A promise that resolves with the HTTP operation result
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

    /**
     * Bridge Guzzle promises to fiber-compatible promises.
     *
     * Converts Guzzle's native promise implementation to work seamlessly
     * with the fiber-based async system. Handles both successful responses
     * and errors, ensuring proper event loop scheduling and fiber context
     * management throughout the promise lifecycle.
     *
     * @param  GuzzlePromiseInterface  $guzzlePromise  The Guzzle promise to bridge
     * @param  callable  $resolve  Callback for successful resolution
     * @param  callable  $reject  Callback for promise rejection
     */
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

/**
 * Bridge for Laravel HTTP client operations in async contexts.
 *
 * Provides a familiar Laravel HTTP client API while adding async capabilities
 * and fiber integration. All methods return promises that can be awaited
 * in async contexts without blocking the event loop.
 *
 * This bridge maintains Laravel's method signatures and behavior patterns,
 * making it easy to convert existing Laravel HTTP code to async operations
 * with minimal changes to the calling code.
 */
class LaravelHttpBridge
{
    /**
     * @var LaravelHttpFactory The Laravel HTTP factory for making requests
     */
    private LaravelHttpFactory $http;

    /**
     * Create a new Laravel HTTP bridge instance.
     *
     * @param  LaravelHttpFactory  $http  The Laravel HTTP factory to bridge
     */
    public function __construct(LaravelHttpFactory $http)
    {
        $this->http = $http;
    }

    /**
     * Perform an async GET request with query parameters.
     *
     * Creates a GET request with optional query parameters that executes
     * asynchronously without blocking the event loop. The request maintains
     * Laravel's standard behavior while adding async capabilities.
     *
     * @param  string  $url  The URL to request
     * @param  array  $query  Optional query parameters to append to the URL
     * @return PromiseInterface A promise that resolves with the Laravel response object
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        return $this->makeRequest('GET', $url, ['query' => $query]);
    }

    /**
     * Perform an async POST request with JSON data.
     *
     * Creates a POST request with JSON-encoded data that executes
     * asynchronously. The data is automatically encoded as JSON and
     * appropriate headers are set for the request.
     *
     * @param  string  $url  The URL to post to
     * @param  array  $data  The data to send as JSON in the request body
     * @return PromiseInterface A promise that resolves with the Laravel response object
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        return $this->makeRequest('POST', $url, ['json' => $data]);
    }

    /**
     * Perform an async PUT request with JSON data.
     *
     * Creates a PUT request with JSON-encoded data that executes
     * asynchronously. Similar to POST but uses the PUT HTTP method
     * for resource updates and replacements.
     *
     * @param  string  $url  The URL to send the PUT request to
     * @param  array  $data  The data to send as JSON in the request body
     * @return PromiseInterface A promise that resolves with the Laravel response object
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        return $this->makeRequest('PUT', $url, ['json' => $data]);
    }

    /**
     * Perform an async DELETE request.
     *
     * Creates a DELETE request that executes asynchronously without
     * blocking the event loop. Used for resource deletion operations
     * in RESTful APIs.
     *
     * @param  string  $url  The URL to send the DELETE request to
     * @return PromiseInterface A promise that resolves with the Laravel response object
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->makeRequest('DELETE', $url);
    }

    /**
     * Execute an HTTP request asynchronously using Laravel's HTTP client.
     *
     * Creates a fiber-wrapped HTTP request that integrates with the event loop
     * system. Handles all HTTP methods and options while maintaining Laravel's
     * familiar API and response format.
     *
     * @param  string  $method  The HTTP method to use
     * @param  string  $url  The URL to request
     * @param  array  $options  Request options (query, json, headers, etc.)
     * @return PromiseInterface A promise that resolves with the Laravel response
     */
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
