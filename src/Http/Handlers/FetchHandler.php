<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

use Psr\SimpleCache\CacheInterface;
use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Http\Traits\FetchOptionTrait;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Handler for fetch-style HTTP requests with advanced options support.
 *
 * This class provides a flexible, fetch-like interface for making HTTP requests
 * with support for streaming, downloads, retry logic, and caching.
 */
class FetchHandler
{
    use FetchOptionTrait;
    
    private StreamingHandler $streamingHandler;
    private static ?CacheInterface $defaultCache = null;

    public function __construct(?StreamingHandler $streamingHandler = null)
    {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler;
    }

    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  An associative array of request options.
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with a Response, StreamingResponse, or download metadata.
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        if ($this->isDownloadRequested($options)) {
            return $this->fetchDownload($url, $options);
        }

        $isStreaming = $this->isStreamingRequested($options);
        $onChunk = $this->extractOnChunkCallback($options);

        if ($isStreaming) {
            return $this->fetchStream($url, $options, $onChunk);
        }

        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        $curlOptions = $this->normalizeFetchOptions($url, $options);

        if ($cacheConfig !== null) {
            return $this->sendRequestWithCache($url, $curlOptions, $cacheConfig, $retryConfig);
        } elseif ($retryConfig !== null) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->executeBasicFetch($url, $curlOptions);
    }

    /**
     * Sends a request with automatic retry logic on failure.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $options  An array of cURL options.
     * @param  RetryConfig  $retryConfig  Configuration object for retry behavior.
     * @return PromiseInterface<Response> A promise that resolves with a Response object or rejects with an HttpException on final failure.
     */
    public function fetchWithRetry(string $url, array $options, RetryConfig $retryConfig): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;
        $attempt = 0;
        $totalAttempts = 0;
        /** @var string|null $requestId */
        $requestId = null;

        $executeRequest = function () use ($url, $options, $retryConfig, $promise, &$attempt, &$totalAttempts, &$requestId, &$executeRequest) {
            $totalAttempts++;

            $requestId = EventLoop::getInstance()->addHttpRequest(
                $url,
                $options,
                function (?string $error, ?string $responseBody, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($retryConfig, $promise, &$attempt, &$totalAttempts, &$executeRequest) {

                    if ($promise->isCancelled()) {
                        return;
                    }

                    $isRetryable = ($error !== null && $retryConfig->isRetryableError($error)) ||
                        ($httpCode !== null && in_array($httpCode, $retryConfig->retryableStatusCodes, true));

                    if ($isRetryable && $attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        $delay = $retryConfig->getDelay($attempt);

                        EventLoop::getInstance()->addTimer($delay, $executeRequest);

                        return;
                    }

                    // If we've exhausted retries or it's not retryable, handle the final result
                    if ($error !== null) {
                        $promise->reject(new HttpException("HTTP Request failed after {$totalAttempts} attempts: {$error}"));
                    } elseif ($isRetryable) {
                        // This means we exceeded max retries with a retryable status code
                        $promise->reject(new HttpException("HTTP Request failed with status {$httpCode} after {$totalAttempts} attempts."));
                    } else {
                        // Success case
                        /** @var array<string, array<string>|string> $normalizedHeaders */
                        $normalizedHeaders = $this->normalizeHeaders($headers);
                        $responseObj = new Response($responseBody ?? '', $httpCode ?? 0, $normalizedHeaders);

                        if ($httpVersion !== null) {
                            $responseObj->setHttpVersion($httpVersion);
                        }

                        $promise->resolve($responseObj);
                    }
                }
            );
        };

        // Start the first request
        $executeRequest();

        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    /**
     * Handles download requests through fetch.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Request options.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with download metadata.
     */
    private function fetchDownload(string $url, array $options): CancellablePromiseInterface
    {
        $destination = $options['download'] ?? $options['save_to'] ?? null;

        if (! is_string($destination)) {
            throw new \InvalidArgumentException('Download destination must be a string path');
        }

        // Normalize options to cURL format
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->downloadFile($url, $destination, $curlOptions);
    }

    /**
     * Checks if download is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return bool True if download is requested.
     */
    private function isDownloadRequested(array $options): bool
    {
        return isset($options['download']) || isset($options['save_to']);
    }

    /**
     * Handles streaming requests through fetch.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Request options.
     * @param  callable|null  $onChunk  Optional chunk callback.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    private function fetchStream(string $url, array $options, ?callable $onChunk = null): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        // Remove headers that might interfere with streaming
        unset($curlOptions[CURLOPT_HEADER]);

        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    /**
     * Checks if streaming is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return bool True if streaming is requested.
     */
    private function isStreamingRequested(array $options): bool
    {
        return isset($options['stream']) && $options['stream'] === true;
    }

    /**
     * Extracts the onChunk callback from options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return callable|null The chunk callback if provided.
     */
    private function extractOnChunkCallback(array $options): ?callable
    {
        if (isset($options['on_chunk']) && is_callable($options['on_chunk'])) {
            return $options['on_chunk'];
        }

        // Also support 'onChunk' for consistency with Request builder
        if (isset($options['onChunk']) && is_callable($options['onChunk'])) {
            return $options['onChunk'];
        }

        return null;
    }

    /**
     * Executes basic fetch without advanced features
     *
     * @param  array<int, mixed>  $curlOptions
     * @return PromiseInterface<Response>
     */
    private function executeBasicFetch(string $url, array $curlOptions): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOptions,
            function (?string $error, ?string $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($promise) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new HttpException("HTTP Request failed: {$error}"));
                } else {
                    $normalizedHeaders = $this->normalizeHeaders($headers);
                    $responseObj = new Response($response ?? '', $httpCode ?? 0, $normalizedHeaders);

                    if ($httpVersion !== null) {
                        $responseObj->setHttpVersion($httpVersion);
                    }

                    $promise->resolve($responseObj);
                }
            }
        );

        $promise->setCancelHandler(function () use ($requestId) {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
        });

        return $promise;
    }

    /**
     * Sends a request with caching support.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  CacheConfig  $cacheConfig  Cache configuration.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    private function sendRequestWithCache(string $url, array $curlOptions, CacheConfig $cacheConfig, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if (($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') !== 'GET') {
            return $this->dispatchRequest($url, $curlOptions, $retryConfig);
        }

        $cache = $cacheConfig->cache ?? self::getDefaultCache();
        $cacheKey = $this->generateCacheKey($url);

        /** @var PromiseInterface<Response> */
        return Async::async(function () use ($cache, $cacheKey, $url, $curlOptions, $cacheConfig, $retryConfig): Response {
            /** @var array{body: string, status: int, headers: array<string, array<string>|string>, expires_at: int}|null $cachedItem */
            $cachedItem = $cache->get($cacheKey);

            if (is_array($cachedItem) && isset($cachedItem['expires_at']) && is_int($cachedItem['expires_at']) && time() < $cachedItem['expires_at']) {
                if (isset($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']) && is_string($cachedItem['body']) && is_int($cachedItem['status']) && is_array($cachedItem['headers'])) {
                    return new Response($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']);
                }
            }

            if (is_array($cachedItem) && $cacheConfig->respectServerHeaders && isset($cachedItem['headers']) && is_array($cachedItem['headers'])) {
                /** @var array<string> $httpHeaders */
                $httpHeaders = [];
                if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
                }

                $cachedHeaders = $cachedItem['headers'];

                if (isset($cachedHeaders['etag'])) {
                    $etagValue = $cachedHeaders['etag'];
                    if (is_string($etagValue)) {
                        $etag = $etagValue;
                    } elseif (is_array($etagValue) && isset($etagValue[0]) && is_string($etagValue[0])) {
                        $etag = $etagValue[0];
                    } else {
                        $etag = null;
                    }

                    if ($etag !== null) {
                        $httpHeaders[] = 'If-None-Match: '.$etag;
                    }
                }

                if (isset($cachedHeaders['last-modified'])) {
                    $lastModifiedValue = $cachedHeaders['last-modified'];
                    if (is_string($lastModifiedValue)) {
                        $lastModified = $lastModifiedValue;
                    } elseif (is_array($lastModifiedValue) && isset($lastModifiedValue[0]) && is_string($lastModifiedValue[0])) {
                        $lastModified = $lastModifiedValue[0];
                    } else {
                        $lastModified = null;
                    }

                    if ($lastModified !== null) {
                        $httpHeaders[] = 'If-Modified-Since: '.$lastModified;
                    }
                }

                $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;
            }

            $response = await($this->dispatchRequest($url, $curlOptions, $retryConfig));

            if ($response->status() === 304 && is_array($cachedItem)) {
                $newExpiry = $this->calculateExpiry($response, $cacheConfig);
                $cachedItem['expires_at'] = $newExpiry;
                $cache->set($cacheKey, $cachedItem, $newExpiry > time() ? $newExpiry - time() : 0);

                if (isset($cachedItem['body'], $cachedItem['headers']) && is_string($cachedItem['body']) && is_array($cachedItem['headers'])) {
                    return new Response($cachedItem['body'], 200, $cachedItem['headers']);
                }
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
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    private function dispatchRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->executeBasicFetch($url, $curlOptions);
    }

    /**
     * Normalizes fetch options from various formats to cURL options.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @return array<int, mixed> Normalized cURL options.
     */
    /**
     * Normalizes fetch options from various formats to cURL options.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @return array<int, mixed> Normalized cURL options.
     */
    public function normalizeFetchOptions(string $url, array $options): array
    {
        // Remove streaming-specific options before processing
        $cleanOptions = array_filter($options, function ($key) {
            return ! in_array($key, [
                'stream',
                'on_chunk',
                'onChunk',
                'download',
                'save_to',
                'retry',
                'cache',
                'retry_config',
                'cache_config',
            ], true);
        }, ARRAY_FILTER_USE_KEY);

        if ($this->isCurlOptionsFormat($cleanOptions)) {
            /** @var array<int, mixed> */
            $curlOptions = array_filter($cleanOptions, fn ($key) => is_int($key), ARRAY_FILTER_USE_KEY);

            $curlOptions[CURLOPT_URL] = $url;

            if (! isset($curlOptions[CURLOPT_RETURNTRANSFER])) {
                $curlOptions[CURLOPT_RETURNTRANSFER] = true;
            }
            if (! isset($curlOptions[CURLOPT_HEADER])) {
                $curlOptions[CURLOPT_HEADER] = true;
            }
            if (! isset($curlOptions[CURLOPT_NOBODY])) {
                $curlOptions[CURLOPT_NOBODY] = false;
            }

            return $curlOptions;
        }

        /** @var array<int, mixed> $curlOptions */
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        if (isset($options['method']) && is_string($options['method'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($options['method']);
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headerStrings = [];
            foreach ($options['headers'] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_scalar($value))) {
                    $headerStrings[] = "{$name}: {$value}";
                }
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headerStrings;
        }

        if (isset($options['http_version']) && is_string($options['http_version'])) {
            $curlOptions[CURLOPT_HTTP_VERSION] = match ($options['http_version']) {
                '2.0', '2' => CURL_HTTP_VERSION_2TLS,
                '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                    ? CURL_HTTP_VERSION_3
                    : CURL_HTTP_VERSION_1_1,
                '1.0' => CURL_HTTP_VERSION_1_0,
                default => CURL_HTTP_VERSION_1_1,
            };
        }

        if (isset($options['protocol']) && is_string($options['protocol'])) {
            $curlOptions[CURLOPT_HTTP_VERSION] = match ($options['protocol']) {
                '2.0', '2' => CURL_HTTP_VERSION_2TLS,
                '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                    ? CURL_HTTP_VERSION_3
                    : CURL_HTTP_VERSION_1_1,
                '1.0' => CURL_HTTP_VERSION_1_0,
                default => CURL_HTTP_VERSION_1_1,
            };
        }

        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['json']) && is_array($options['json'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($options['json']);
            $headers = [];
            if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $headers = $curlOptions[CURLOPT_HTTPHEADER];
            }
            $headers[] = 'Content-Type: application/json';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (isset($options['form']) && is_array($options['form'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($options['form']);
            $headers = [];
            if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $headers = $curlOptions[CURLOPT_HTTPHEADER];
            }
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = (int) $options['timeout'];
        }

        if (isset($options['connect_timeout']) && is_numeric($options['connect_timeout'])) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = (int) $options['connect_timeout'];
        }

        if (isset($options['follow_redirects'])) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = (bool) $options['follow_redirects'];
        }

        if (isset($options['max_redirects']) && is_numeric($options['max_redirects'])) {
            $curlOptions[CURLOPT_MAXREDIRS] = (int) $options['max_redirects'];
        }

        if (isset($options['verify_ssl'])) {
            $verifySSL = (bool) $options['verify_ssl'];
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = $verifySSL;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = $verifySSL ? 2 : 0;
        }

        if (isset($options['user_agent']) && is_string($options['user_agent'])) {
            $curlOptions[CURLOPT_USERAGENT] = $options['user_agent'];
        }

        if (isset($options['auth']) && is_array($options['auth'])) {
            $auth = $options['auth'];

            if (isset($auth['bearer']) && is_string($auth['bearer'])) {
                $headers = [];
                if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $headers = $curlOptions[CURLOPT_HTTPHEADER];
                }
                $headers[] = 'Authorization: Bearer '.$auth['bearer'];
                $curlOptions[CURLOPT_HTTPHEADER] = $headers;
            }

            if (isset($auth['basic']) && is_array($auth['basic'])) {
                $basic = $auth['basic'];
                if (
                    isset($basic['username'], $basic['password']) &&
                    is_string($basic['username']) && is_string($basic['password'])
                ) {
                    $curlOptions[CURLOPT_USERPWD] = $basic['username'].':'.$basic['password'];
                    $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                }
            }
        }

        return $curlOptions;
    }

    /**
     * Determines if the options array is in cURL format (integer keys) or fetch format (string keys).
     *
     * @param  array<int|string, mixed>  $options  The options to check.
     * @return bool True if options are in cURL format.
     */
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
     * Normalizes headers array to the expected format.
     *
     * @param  array<mixed>  $headers  The headers to normalize.
     * @return array<string, array<string>|string> Normalized headers.
     */
    private function normalizeHeaders(array $headers): array
    {
        /** @var array<string, array<string>|string> $normalized */
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                if (is_string($value)) {
                    $normalized[$key] = $value;
                } elseif (is_array($value)) {
                    $stringValues = array_filter($value, 'is_string');
                    if (count($stringValues) > 0) {
                        $normalized[$key] = array_values($stringValues);
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Generates the unique cache key for a given URL.
     *
     * @param  string  $url  The URL to generate a cache key for.
     * @return string The unique cache key.
     */
    private function generateCacheKey(string $url): string
    {
        return 'http_'.sha1($url);
    }

    /**
     * Lazily creates and returns a default PSR-16 cache instance.
     *
     * @return CacheInterface The default cache instance.
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
     * Calculates the expiry timestamp based on Cache-Control headers or the default TTL from config.
     *
     * @param  Response  $response  The HTTP response.
     * @param  CacheConfig  $cacheConfig  The cache configuration.
     * @return int The expiry timestamp.
     */
    private function calculateExpiry(Response $response, CacheConfig $cacheConfig): int
    {
        if ($cacheConfig->respectServerHeaders) {
            $header = $response->getHeaderLine('Cache-Control');
            if ($header !== '' && preg_match('/max-age=(\d+)/', $header, $matches) === 1) {
                return time() + (int) $matches[1];
            }
        }

        return time() + $cacheConfig->ttlSeconds;
    }
}
