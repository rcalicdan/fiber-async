<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * HTTP Facade for clean, static access to HTTP operations
 *
 * @method static Request request()
 * @method static PromiseInterface<Response> get(string $url, array $query = [])
 * @method static PromiseInterface<Response> post(string $url, array $data = [])
 * @method static PromiseInterface<Response> put(string $url, array $data = [])
 * @method static PromiseInterface<Response> delete(string $url)
 * @method static PromiseInterface<Response> fetch(string $url, array $options = [])
 * @method static PromiseInterface<StreamingResponse> stream(string $url, array $options = [], ?callable $onChunk = null)
 * @method static PromiseInterface<array> download(string $url, string $destination, array $options = [])
 */
class AsyncHttp
{
    /**
     * @var HttpHandler|null Singleton instance
     */
    private static ?HttpHandler $instance = null;

    /**
     * Get the HTTP handler instance
     */
    private static function getInstance(): HttpHandler
    {
        if (self::$instance === null) {
            self::$instance = new HttpHandler;
        }
        return self::$instance;
    }

    /**
     * Create a new HTTP request builder
     */
    public static function request(): Request
    {
        return self::getInstance()->request();
    }

    /**
     * Quick GET request
     * @return PromiseInterface<Response>
     */
    public static function get(string $url, array $query = []): PromiseInterface
    {
        return self::getInstance()->get($url, $query);
    }

    /**
     * Quick POST request with JSON data
     * @return PromiseInterface<Response>
     */
    public static function post(string $url, array $data = []): PromiseInterface
    {
        return self::getInstance()->post($url, $data);
    }

    /**
     * Quick PUT request
     * @return PromiseInterface<Response>
     */
    public static function put(string $url, array $data = []): PromiseInterface
    {
        return self::getInstance()->put($url, $data);
    }

    /**
     * Quick DELETE request
     * @return PromiseInterface<Response>
     */
    public static function delete(string $url): PromiseInterface
    {
        return self::getInstance()->delete($url);
    }

    /**
     * Enhanced fetch method
     * @return PromiseInterface<Response>
     */
    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return self::getInstance()->fetch($url, $options);
    }

    /**
     * Stream a response with optional chunk handling
     * @return PromiseInterface<StreamingResponse>
     */
    public static function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        return self::getInstance()->stream($url, $options, $onChunk);
    }

    /**
     * Download a file
     * @return PromiseInterface<array>
     */
    public static function download(string $url, string $destination, array $options = []): PromiseInterface
    {
        return self::getInstance()->download($url, $destination, $options);
    }

    /**
     * Reset the singleton instance (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Set a custom HTTP handler instance
     */
    public static function setInstance(HttpHandler $handler): void
    {
        self::$instance = $handler;
    }

    /**
     * Handle dynamic static calls
     */
    public static function __callStatic(string $method, array $arguments)
    {
        return self::getInstance()->{$method}(...$arguments);
    }
}
