<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

use Psr\SimpleCache\CacheInterface;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Core handler for creating and dispatching asynchronous HTTP requests.
 *
 * This class acts as the workhorse for the AsyncHttp Api, translating high-level
 * requests into low-level operations managed by the event loop.
 */
class HttpHandler
{
    private StreamingHandler $streamingHandler;
    private static ?CacheInterface $defaultCache = null;

    public function __construct()
    {
        $this->streamingHandler = new StreamingHandler;
    }

    /**
     * Creates a new fluent HTTP request builder instance.
     *
     * @return Request The request builder.
     */
    public function request(): Request
    {
        return new Request($this);
    }

    /**
     * Performs a quick, asynchronous GET request.
     *
     * @param  string  $url  The target URL.
     * @param  array  $query  Optional query parameters.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        return $this->request()->get($url, $query);
    }

    /**
     * Performs a quick, asynchronous POST request with a JSON payload.
     *
     * @param  string  $url  The target URL.
     * @param  array  $data  Data to be JSON-encoded and sent as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->post($url, $data);
    }

    /**
     * Performs a quick, asynchronous PUT request.
     *
     * @param  string  $url  The target URL.
     * @param  array  $data  Data to be JSON-encoded and sent as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->put($url, $data);
    }

    /**
     * Performs a quick, asynchronous DELETE request.
     *
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->request()->delete($url);
    }

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * Ideal for large responses that should not be fully loaded into memory.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array  $options  Advanced cURL or request options.
     * @param  callable|null  $onChunk  An optional callback to execute for each received data chunk. `function(string $chunk): void`
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse object.
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    /**
     * Asynchronously downloads a file from a URL to a specified destination.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array  $options  Advanced cURL or request options.
     * @return CancellablePromiseInterface<array{file: string, status: int|null, headers: array<mixed>}> A promise that resolves with download metadata.
     */
    public function download(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->downloadFile($url, $destination, $curlOptions);
    }

    /**
     * Creates a new stream from a string.
     *
     * @param  string  $content  The initial content of the stream.
     * @return Stream A new Stream object.
     */
    public function createStream(string $content = ''): Stream
    {
        $resource = fopen('php://temp', 'w+b');
        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new Stream($resource);
    }

    /**
     * Creates a new stream from a file path.
     *
     * @param  string  $path  The path to the file.
     * @param  string  $mode  The mode to open the file with (e.g., 'rb', 'w+b').
     * @return Stream A new Stream object wrapping the file resource.
     *
     * @throws RuntimeException if the file cannot be opened.
     */
    public function createStreamFromFile(string $path, string $mode = 'rb'): Stream
    {
        $resource = @fopen($path, $mode);
        if (! $resource) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        return new Stream($resource, $path);
    }

    /**
     * Generates the unique cache key for a given URL.
     * This method is the single source of truth for cache key generation,
     * ensuring consistency between caching and invalidation logic.
     *
     * @param  string  $url  The URL to generate a cache key for.
     * @return string The unique cache key.
     */
    public static function generateCacheKey(string $url): string
    {
        return 'http_' . sha1($url);
    }

    /**
     * Lazily creates and returns a default PSR-16 cache instance.
     * This enables zero-config caching for the user.
     */
    private static function getDefaultCache(): CacheInterface
    {
        if (self::$defaultCache === null) {
            $psr6Cache = new FilesystemAdapter('http', 0, 'cache');
            self::$defaultCache = new Psr16Cache($psr6Cache);
        }

        return self::$defaultCache;
    }

    /**
     * The main entry point for sending a request from the Request builder.
     * It intelligently applies caching logic before proceeding to dispatch the request.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if ($cacheConfig === null || ($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') !== 'GET') {
            return $this->dispatchRequest($url, $curlOptions, $retryConfig);
        }

        $cache = $cacheConfig->cache ?? self::getDefaultCache();
        $cacheKey = self::generateCacheKey($url);

        return async(function () use ($cache, $cacheKey, $url, $curlOptions, $cacheConfig, $retryConfig) {
            $cachedItem = $cache->get($cacheKey);

            if ($cachedItem && time() < $cachedItem['expires_at']) {
                return new Response($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']);
            }

            if ($cachedItem && $cacheConfig->respectServerHeaders) {
                if (isset($cachedItem['headers']['etag'])) {
                    $curlOptions[CURLOPT_HTTPHEADER][] = 'If-None-Match: ' . $cachedItem['headers']['etag'][0];
                }
                if (isset($cachedItem['headers']['last-modified'])) {
                    $curlOptions[CURLOPT_HTTPHEADER][] = 'If-Modified-Since: ' . $cachedItem['headers']['last-modified'][0];
                }
            }

            $response = await($this->dispatchRequest($url, $curlOptions, $retryConfig));

            if ($response->status() === 304 && $cachedItem) {
                $newExpiry = $this->calculateExpiry($response, $cacheConfig);
                $cachedItem['expires_at'] = $newExpiry;
                $cache->set($cacheKey, $cachedItem, $newExpiry > time() ? $newExpiry - time() : 0);

                return new Response($cachedItem['body'], 200, $cachedItem['headers']);
            }

            if ($response->ok()) {
                $expiry = $this->calculateExpiry($response, $cacheConfig);
                if ($expiry > time()) {
                    $ttl = $expiry - time();
                    $cache->set($cacheKey, [
                        'body' => (string) $response->getBody(),
                        'status' => $response->status(),
                        'headers' => $response->getHeaders(),
                        'expires_at' => $expiry,
                    ], $ttl);
                }
            }

            return $response;
        })();
    }

    /**
     * Dispatches the request to the network, applying retry logic if configured.
     */
    private function dispatchRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        if ($retryConfig) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->fetch($url, $curlOptions);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests.
     *
     * @param  string  $url  The target URL.
     * @param  array  $options  An associative array of request options (method, headers, body, etc.).
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function fetch(string $url, array $options = []): PromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $promise = new CancellablePromise;

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOptions,
            function ($error, $response, $httpCode, $headers = []) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error) {
                    $promise->reject(new HttpException("HTTP Request failed: {$error}"));
                } else {
                    $promise->resolve(new Response($response, $httpCode, $headers));
                }
            }
        );

        $promise->setCancelHandler(function () use ($requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    /**
     * Sends a request with automatic retry logic on failure.
     *
     * @param  string  $url  The target URL.
     * @param  array  $options  An array of cURL options.
     * @param  RetryConfig  $retryConfig  Configuration object for retry behavior.
     * @return PromiseInterface<Response> A promise that resolves with a Response object or rejects with an HttpException on final failure.
     */
    public function fetchWithRetry(string $url, array $options, RetryConfig $retryConfig): PromiseInterface
    {
        $promise = new CancellablePromise;
        $attempt = 0;
        $totalAttempts = 0;
        $requestId = null;

        $executeRequest = function () use ($url, $options, $retryConfig, $promise, &$attempt, &$totalAttempts, &$requestId, &$executeRequest) {
            $totalAttempts++;
            $requestId = EventLoop::getInstance()->addHttpRequest(
                $url,
                $options,
                function ($error, $responseBody, $httpCode, $headers = []) use ($retryConfig, $promise, &$attempt, $totalAttempts, &$executeRequest) {
                    if ($promise->isCancelled()) {
                        return;
                    }

                    $isRetryable = ($error && $retryConfig->isRetryableError($error)) ||
                        ($httpCode && in_array($httpCode, $retryConfig->retryableStatusCodes));

                    if ($isRetryable && $attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        $delay = $retryConfig->getDelay($attempt);
                        EventLoop::getInstance()->addTimer($delay, $executeRequest);

                        return;
                    }

                    if ($error) {
                        $promise->reject(new HttpException("HTTP Request failed after {$totalAttempts} attempts: {$error}"));
                    } elseif ($isRetryable) {
                        $promise->reject(new HttpException("HTTP Request failed with status {$httpCode} after {$totalAttempts} attempts."));
                    } else {
                        $promise->resolve(new Response($responseBody, $httpCode, $headers));
                    }
                }
            );
        };

        $executeRequest();

        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    private function normalizeFetchOptions(string $url, array $options): array
    {
        if ($this->isCurlOptionsFormat($options)) {
            return $options;
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        if (isset($options['method'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($options['method']);
        }

        if (isset($options['headers'])) {
            $headerStrings = [];
            foreach ($options['headers'] as $name => $value) {
                $headerStrings[] = "{$name}: {$value}";
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headerStrings;
        }

        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = $options['timeout'];
        }

        if (isset($options['follow_redirects'])) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = (bool) $options['follow_redirects'];
        }

        if (isset($options['verify_ssl'])) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = (bool) $options['verify_ssl'];
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = $options['verify_ssl'] ? 2 : 0;
        }

        if (isset($options['user_agent'])) {
            $curlOptions[CURLOPT_USERAGENT] = $options['user_agent'];
        }

        return $curlOptions;
    }

    private function isCurlOptionsFormat(array $options): bool
    {
        foreach (array_keys($options) as $key) {
            if (is_int($key) && $key > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculates the expiry timestamp based on Cache-Control headers or the default TTL from config.
     */
    private function calculateExpiry(Response $response, CacheConfig $cacheConfig): int
    {
        if ($cacheConfig->respectServerHeaders) {
            $header = $response->getHeaderLine('Cache-Control');
            if ($header && preg_match('/max-age=(\d+)/', $header, $matches)) {
                return time() + (int) $matches[1];
            }
        }

        return time() + $cacheConfig->ttlSeconds;
    }
}
