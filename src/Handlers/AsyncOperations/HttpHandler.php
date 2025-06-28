<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\AsyncPromise;
use Rcalicdan\FiberAsync\Bridges\HttpClientBridge;
use Rcalicdan\FiberAsync\CancellablePromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;

/**
 * Handles asynchronous HTTP operations and client integrations.
 *
 * This handler provides various methods for making HTTP requests asynchronously,
 * including integration with popular HTTP clients like Guzzle and Laravel's
 * HTTP client. It abstracts the complexity of async HTTP operations.
 */
final readonly class HttpHandler
{
    /**
     * Make an asynchronous HTTP request using the built-in fetch mechanism.
     *
     * This method creates an HTTP request that will be processed asynchronously
     * by the event loop's HTTP request manager.
     *
     * @param  string  $url  The URL to request
     * @param  array  $options  Request options (headers, method, body, etc.)
     * @return PromiseInterface Promise that resolves with response data
     */
    public function fetch(string $url, array $options = []): PromiseInterface
    {
        $promise = new CancellablePromise();
        $requestId = null;

        $requestId = AsyncEventLoop::getInstance()->addHttpRequest($url, $options, function ($error, $response, $httpCode) use ($promise) {
            if ($promise->isCancelled()) {
                return; // Don't resolve if already cancelled
            }

            if ($error) {
                $promise->reject(new Exception('HTTP Request failed: ' . $error));
            } else {
                $promise->resolve([
                    'body' => $response,
                    'status' => $httpCode,
                    'ok' => $httpCode >= 200 && $httpCode < 300,
                ]);
            }
        });

        // Set up cancellation handler AFTER we have the request ID
        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                AsyncEventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    /**
     * Make an asynchronous HTTP request using Guzzle HTTP client.
     *
     * This method delegates to the HttpClientBridge to provide Guzzle
     * integration with the async event loop.
     *
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  string  $url  The URL to request
     * @param  array  $options  Guzzle-compatible request options
     * @return PromiseInterface Promise that resolves with Guzzle response
     */
    public function guzzle(string $method, string $url, array $options = []): PromiseInterface
    {
        return HttpClientBridge::getInstance()->guzzle($method, $url, $options);
    }

    /**
     * Get Laravel HTTP client instance for async operations.
     *
     * This method provides access to Laravel's HTTP client configured
     * for use with the async event loop.
     *
     * @return mixed Laravel HTTP client instance
     */
    public function http()
    {
        return HttpClientBridge::getInstance()->laravel();
    }

    /**
     * Wrap a synchronous HTTP call to make it asynchronous.
     *
     * This method takes any synchronous HTTP operation and wraps it
     * to work within the async event loop context.
     *
     * @param  callable  $syncCall  The synchronous HTTP call to wrap
     * @return PromiseInterface Promise that resolves with the call result
     */
    public function wrapSync(callable $syncCall): PromiseInterface
    {
        return HttpClientBridge::getInstance()->wrap($syncCall);
    }
}
