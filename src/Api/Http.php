<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Interfaces\MessageInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\RequestInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\StreamInterface;
use Rcalicdan\FiberAsync\Http\Interfaces\UriInterface;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Http\Testing\TestingHttpHandler;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * A static API for clean, expressive, and asynchronous HTTP operations.
 *
 * This class provides a simple, static entry point for all HTTP-related tasks,
 * including GET, POST, streaming, and file downloads. It abstracts away the
 * underlying handler and event loop management for a more convenient API.
 *
 * Direct HTTP methods from HttpHandler:
 *
 * @method static PromiseInterface<Response> get(string $url, array<string, mixed> $query = []) Performs a GET request.
 * @method static PromiseInterface<Response> post(string $url, array<string, mixed> $data = []) Performs a POST request.
 * @method static PromiseInterface<Response> put(string $url, array<string, mixed> $data = []) Performs a PUT request.
 * @method static PromiseInterface<Response> delete(string $url) Performs a DELETE request.
 * @method static PromiseInterface<Response> fetch(string $url, array<int|string, mixed> $options = []) A flexible, fetch-like request method.
 * @method static CancellablePromiseInterface<StreamingResponse> stream(string $url, array<int|string, mixed> $options = [], ?callable $onChunk = null) Streams a response body.
 * @method static CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> download(string $url, string $destination, array<int|string, mixed> $options = []) Downloads a file.
 *
 * Request builder methods:
 * @method static Request cache(int $ttlSeconds = 3600, bool $respectServerHeaders = true) Start building a request with caching enabled.
 * @method static Request cacheWith(CacheConfig $config) Start building a request with custom cache configuration.
 * @method static Request timeout(int $seconds) Start building a request with timeout.
 * @method static Request connectTimeout(int $seconds) Start building a request with connection timeout.
 * @method static Request headers(array<string, string> $headers) Start building a request with headers.
 * @method static Request header(string $name, string $value) Start building a request with a single header.
 * @method static Request contentType(string $type) Start building a request with Content-Type header.
 * @method static Request accept(string $type) Start building a request with Accept header.
 * @method static Request bearerToken(string $token) Start building a request with bearer token.
 * @method static Request basicAuth(string $username, string $password) Start building a request with basic auth.
 * @method static Request retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0) Start building a request with retry logic.
 * @method static Request retryWith(RetryConfig $config) Start building a request with custom retry configuration.
 * @method static Request noRetry() Start building a request with retries disabled.
 * @method static Request redirects(bool $follow = true, int $max = 5) Start building a request with redirect configuration.
 * @method static Request verifySSL(bool $verify = true) Start building a request with SSL verification configuration.
 * @method static Request userAgent(string $userAgent) Start building a request with custom User-Agent.
 * @method static Request body(string $content) Start building a request with string body.
 * @method static Request json(array<string, mixed> $data) Start building a request with JSON body.
 * @method static Request form(array<string, mixed> $data) Start building a request with form data.
 * @method static Request multipart(array<string, mixed> $data) Start building a request with multipart data.
 *
 * PSR-7 Message interface methods (immutable with* methods):
 * @method static MessageInterface withProtocolVersion(string $version) Return an instance with the specified HTTP protocol version.
 * @method static array<string, string[]> getHeaders() Retrieves all message header values.
 * @method static bool hasHeader(string $name) Checks if a header exists by the given case-insensitive name.
 * @method static string[] getHeader(string $name) Retrieves a message header value by the given case-insensitive name.
 * @method static string getHeaderLine(string $name) Retrieves a comma-separated string of the values for a single header.
 * @method static MessageInterface withHeader(string $name, string|string[] $value) Return an instance with the provided value replacing the specified header.
 * @method static MessageInterface withAddedHeader(string $name, string|string[] $value) Return an instance with the specified header appended with the given value.
 * @method static MessageInterface withoutHeader(string $name) Return an instance without the specified header.
 * @method static StreamInterface getBody() Gets the body of the message.
 * @method static MessageInterface withBody(StreamInterface $body) Return an instance with the specified message body.
 * @method static string getProtocolVersion() Retrieves the HTTP protocol version as a string.
 *
 * PSR-7 Request interface methods:
 * @method static string getRequestTarget() Retrieves the message's request target.
 * @method static RequestInterface withRequestTarget(string $requestTarget) Return an instance with the specific request-target.
 * @method static string getMethod() Retrieves the HTTP method of the request.
 * @method static RequestInterface withMethod(string $method) Return an instance with the provided HTTP method.
 * @method static UriInterface getUri() Retrieves the URI instance.
 * @method static RequestInterface withUri(UriInterface $uri, bool $preserveHost = false) Returns an instance with the provided URI.
 *
 * Request streaming methods:
 * @method static CancellablePromiseInterface<StreamingResponse> streamPost(string $url, mixed $body = null, ?callable $onChunk = null) Streams the response body of a POST request.
 *
 * Request execution methods:
 * @method static PromiseInterface<Response> send(string $method, string $url) Dispatches the configured request.
 */
class Http
{
    /** @var HttpHandler|null Singleton instance of the core HTTP handler. */
    private static ?HttpHandler $instance = null;

    /** @var TestingHttpHandler|null Testing handler instance when in testing mode. */
    private static ?TestingHttpHandler $testingInstance = null;

    /** @var bool Flag to track if we're in testing mode. */
    private static bool $isTesting = false;

    /**
     * Lazily initializes and returns the singleton HttpHandler instance.
     */
    private static function getInstance(): HttpHandler
    {
        if (self::$isTesting && self::$testingInstance !== null) {
            return self::$testingInstance;
        }

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
     * Enable testing mode with a clean TestingHttpHandler instance.
     *
     * This method switches the Http client to use a TestingHttpHandler instead
     * of the regular HttpHandler, allowing you to mock requests and responses
     * for testing purposes.
     *
     * @param  array<string, mixed>  $settings  Optional global testing settings
     * @return TestingHttpHandler The testing handler for configuration
     */
    public static function testing(): TestingHttpHandler
    {
        self::$isTesting = true;

        if (self::$testingInstance === null) {
            self::$testingInstance = new TestingHttpHandler;
        }

        return self::$testingInstance;
    }

    /**
     * Get the current testing handler instance.
     *
     * @return TestingHttpHandler|null The testing handler if in testing mode, null otherwise
     *
     * @throws \RuntimeException If not in testing mode
     */
    public static function getTestingHandler(): TestingHttpHandler
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::testing() first.');
        }

        return self::$testingInstance;
    }

    /**
     * Check if Http is currently in testing mode.
     *
     * @return bool True if in testing mode, false otherwise
     */
    public static function isTesting(): bool
    {
        return self::$isTesting;
    }

    /**
     * Disable testing mode and return to normal HTTP operations.
     *
     * This restores the Http client to use the regular HttpHandler,
     * clearing all mocked requests and testing state.
     */
    public static function stopTesting(): void
    {
        self::$isTesting = false;
        self::$testingInstance = null;
    }

    /**
     * Resets the singleton instance. Useful for testing environments.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$testingInstance = null;
        self::$isTesting = false;
    }

    /**
     * Allows setting a custom HttpHandler instance, primarily for mocking during tests.
     *
     * @param  HttpHandler  $handler  The custom handler instance.
     */
    public static function setInstance(HttpHandler $handler): void
    {
        if ($handler instanceof TestingHttpHandler) {
            self::$isTesting = true;
            self::$testingInstance = $handler;
        } else {
            self::$isTesting = false;
            self::$instance = $handler;
        }
    }

    /**
     * Convenience method to quickly mock a request in testing mode.
     *
     * @param  string  $method  HTTP method to mock (default: '*' for any)
     *
     * @throws \RuntimeException If not in testing mode
     */
    public static function mock(string $method = '*'): \Rcalicdan\FiberAsync\Http\Testing\MockRequestBuilder
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::testing() first.');
        }

        return self::$testingInstance->mock($method);
    }

    /**
     * Assert that a specific request was made during testing.
     *
     * @param  string  $method  HTTP method
     * @param  string  $url  URL pattern
     * @param  array<string, mixed>  $options  Additional matching options
     *
     * @throws \RuntimeException If not in testing mode
     * @throws \Exception If assertion fails
     */
    public static function assertRequestMade(string $method, string $url, array $options = []): void
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::testing() first.');
        }

        self::$testingInstance->assertRequestMade($method, $url, $options);
    }

    /**
     * Assert that no HTTP requests were made during testing.
     *
     * @throws \RuntimeException If not in testing mode
     * @throws \Exception If assertion fails
     */
    public static function assertNoRequestsMade(): void
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::testing() first.');
        }

        self::$testingInstance->assertNoRequestsMade();
    }

    /**
     * Assert a specific number of requests were made during testing.
     *
     * @param  int  $expected  Expected number of requests
     *
     * @throws \RuntimeException If not in testing mode
     * @throws \Exception If assertion fails
     */
    public static function assertRequestCount(int $expected): void
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::testing() first.');
        }

        self::$testingInstance->assertRequestCount($expected);
    }

    /**
     * Magic method to handle dynamic static calls.
     *
     * This enables both direct HTTP methods (get, post, etc.) and request builder methods
     * (cache, timeout, headers, etc.) to be called directly on Http.
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

        throw new \BadMethodCallException("Method {$method} does not exist on ".static::class);
    }
}
