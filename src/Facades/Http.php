<?php

namespace Rcalicdan\FiberAsync\Facades;

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Handlers\AsyncOperations\HttpHandler;
use Rcalicdan\FiberAsync\Http\Request;

/**
 * HTTP Facade for clean, static access to HTTP operations
 * 
 * @method static Request request()
 * @method static PromiseInterface get(string $url, array $query = [])
 * @method static PromiseInterface post(string $url, array $data = [])
 * @method static PromiseInterface put(string $url, array $data = [])
 * @method static PromiseInterface delete(string $url)
 * @method static PromiseInterface fetch(string $url, array $options = [])
 */
class Http
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
            self::$instance = new HttpHandler();
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
     */
    public static function get(string $url, array $query = []): PromiseInterface
    {
        return self::getInstance()->get($url, $query);
    }

    /**
     * Quick POST request with JSON data
     */
    public static function post(string $url, array $data = []): PromiseInterface
    {
        return self::getInstance()->post($url, $data);
    }

    /**
     * Quick PUT request
     */
    public static function put(string $url, array $data = []): PromiseInterface
    {
        return self::getInstance()->put($url, $data);
    }

    /**
     * Quick DELETE request
     */
    public static function delete(string $url): PromiseInterface
    {
        return self::getInstance()->delete($url);
    }

    /**
     * Enhanced fetch method
     */
    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return self::getInstance()->fetch($url, $options);
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