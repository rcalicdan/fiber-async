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
 * @method static Request request() Creates a new fluent request builder.
 * @method static PromiseInterface<Response> get(string $url, array<string, mixed> $query = []) Performs a GET request.
 * @method static PromiseInterface<Response> post(string $url, array<string, mixed> $data = []) Performs a POST request.
 * @method static PromiseInterface<Response> put(string $url, array<string, mixed> $data = []) Performs a PUT request.
 * @method static PromiseInterface<Response> delete(string $url) Performs a DELETE request.
 * @method static PromiseInterface<Response> fetch(string $url, array<int|string, mixed> $options = []) A flexible, fetch-like request method.
 * @method static PromiseInterface<StreamingResponse> stream(string $url, array<int|string, mixed> $options = [], ?callable $onChunk = null) Streams a response body.
 * @method static CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> download(string $url, string $destination, array<int|string, mixed> $options = []) Downloads a file.
 */
class AsyncHttp
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
     * Performs a quick, asynchronous GET request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $query  Optional query parameters.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public static function get(string $url, array $query = []): PromiseInterface
    {
        return self::getInstance()->get($url, $query);
    }

    /**
     * Performs a quick, asynchronous POST request with a JSON payload.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  Data to be JSON-encoded.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public static function post(string $url, array $data = []): PromiseInterface
    {
        return self::getInstance()->post($url, $data);
    }

    /**
     * Performs a quick, asynchronous PUT request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  Data to be JSON-encoded.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public static function put(string $url, array $data = []): PromiseInterface
    {
        return self::getInstance()->put($url, $data);
    }

    /**
     * Performs a quick, asynchronous DELETE request.
     *
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public static function delete(string $url): PromiseInterface
    {
        return self::getInstance()->delete($url);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Associative array of request options (method, headers, body, etc.).
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return self::getInstance()->fetch($url, $options);
    }

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array<int|string, mixed>  $options  Advanced cURL or request options.
     * @param  callable|null  $onChunk  Optional callback for each data chunk.
     * @return CancellablePromiseInterface<StreamingResponse> A promise resolving with a StreamingResponse object.
     */
    public static function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        return self::getInstance()->stream($url, $options, $onChunk);
    }

    /**
     * Asynchronously downloads a file from a URL.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array<int|string, mixed>  $options  Advanced cURL or request options.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise resolving with download metadata.
     */
    public static function download(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        return self::getInstance()->download($url, $destination, $options);
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
     * Magic method to handle dynamic static calls and proxy them to the handler instance.
     *
     * @param  string  $method  The method name.
     * @param  array<mixed>  $arguments  The arguments to pass to the method.
     * @return mixed The result of the proxied method call.
     */
    public static function __callStatic(string $method, array $arguments)
    {
        return self::getInstance()->{$method}(...$arguments);
    }
}
