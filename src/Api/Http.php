<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A static API for clean, expressive, and asynchronous HTTP operations.
 *
 * This class provides a simple, static entry point for all HTTP-related tasks,
 * including GET, POST, streaming, and file downloads. It abstracts away the
 * underlying handler and event loop management for a more convenient API.
 *
 * @method static PromiseInterface<Response> get(string $url, array<string, mixed> $query = []) Performs a GET request.
 * @method static PromiseInterface<Response> post(string $url, array<string, mixed> $data = []) Performs a POST request.
 * @method static PromiseInterface<Response> put(string $url, array<string, mixed> $data = []) Performs a PUT request.
 * @method static PromiseInterface<Response> delete(string $url) Performs a DELETE request.
 * @method static PromiseInterface<Response> fetch(string $url, array<int|string, mixed> $options = []) A flexible, fetch-like request method.
 * @method static PromiseInterface<StreamingResponse> stream(string $url, array<int|string, mixed> $options = [], ?callable $onChunk = null) Streams a response body.
 * @method static CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> download(string $url, string $destination, array<int|string, mixed> $options = []) Downloads a file.
 * @method static Request cache(int $ttlSeconds = 3600, bool $respectServerHeaders = true) Start building a request with caching enabled.
 * @method static Request timeout(int $seconds) Start building a request with timeout.
 * @method static Request headers(array<string, string> $headers) Start building a request with headers.
 * @method static Request bearerToken(string $token) Start building a request with bearer token.
 * @method static Request basicAuth(string $username, string $password) Start building a request with basic auth.
 * @method static Request retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0) Start building a request with retry logic.
 */
class Http
{
    /** @var HttpHandler|null Singleton instance of the core HTTP handler. */
    private static ?HttpHandler $instance = null;

    /**
     * Lazily initializes and returns the singleton HttpHandler instance.
     */
    private static function getInstance(): HttpHandler
    {
        if (self::$instance === null) {
            self::$instance = new HttpHandler;
        }

        return self::$instance;
    }

    /**
     * Creates a new fluent HTTP request builder.
     *
     * @return Request The request builder instance.
     */
    public static function request(): Request
    {
        return self::getInstance()->request();
    }

    /**
     * Resets the singleton instance. Useful for testing environments.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Allows setting a custom HttpHandler instance, primarily for mocking during tests.
     *
     * @param  HttpHandler  $handler  The custom handler instance.
     */
    public static function setInstance(HttpHandler $handler): void
    {
        self::$instance = $handler;
    }

    /**
     * Magic method to handle dynamic static calls.
     * 
     * This enables both direct HTTP methods (get, post, etc.) and request builder methods
     * (cache, timeout, headers, etc.) to be called directly on AsyncHttp.
     *
     * @param  string  $method  The method name.
     * @param  array<mixed>  $arguments  The arguments to pass to the method.
     * @return mixed The result of the proxied method call.
     */
    public static function __callStatic(string $method, array $arguments)
    {
        $handler = self::getInstance();

        $directMethods = ['get', 'post', 'put', 'delete', 'fetch', 'stream', 'download'];

        if (in_array($method, $directMethods)) {
            return $handler->{$method}(...$arguments);
        }

        $request = $handler->request();
        if (method_exists($request, $method)) {
            return $request->{$method}(...$arguments);
        }

        if (method_exists($handler, $method)) {
            return $handler->{$method}(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }
}
