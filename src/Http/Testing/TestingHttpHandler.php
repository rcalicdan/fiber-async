<?php

namespace Rcalicdan\FiberAsync\Http\Testing;

use Exception;
use Psr\SimpleCache\CacheInterface;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Interfaces\CookieJarInterface;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Http\Testing\Services\CookieManager;
use Rcalicdan\FiberAsync\Http\Testing\Services\FileManager;
use Rcalicdan\FiberAsync\Http\Testing\Services\NetworkSimulator;
use Rcalicdan\FiberAsync\Http\Testing\Services\RequestMatcher;
use Rcalicdan\FiberAsync\Http\Testing\Services\ResponseFactory;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Robust HTTP testing handler with comprehensive mocking capabilities.
 */
class TestingHttpHandler extends HttpHandler
{
    /** @var array<MockedRequest> */
    private array $mockedRequests = [];

    /** @var array<RecordedRequest> */
    private array $requestHistory = [];

    private array $globalSettings = [
        'record_requests' => true,
        'strict_matching' => false,
        'allow_passthrough' => true,
    ];

    private FileManager $fileManager;
    private NetworkSimulator $networkSimulator;
    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private CookieManager $cookieManager;

    public function __construct()
    {
        parent::__construct();
        $this->fileManager = new FileManager;
        $this->networkSimulator = new NetworkSimulator;
        $this->requestMatcher = new RequestMatcher;
        $this->responseFactory = new ResponseFactory($this->networkSimulator);
        $this->cookieManager = new CookieManager;
    }

    public function mock(string $method = '*'): MockRequestBuilder
    {
        return new MockRequestBuilder($this, $method);
    }

    public function addMockedRequest(MockedRequest $request): void
    {
        $this->mockedRequests[] = $request;
    }

    /**
     * Get the cookie testing service.
     */
    public function cookies(): CookieManager
    {
        return $this->cookieManager;
    }

    /**
     * Enable automatic cookie management for all requests.
     */
    public function withGlobalCookieJar(?CookieJarInterface $jar = null): self
    {
        if ($jar === null) {
            $jar = $this->cookieManager->createCookieJar();
        }

        $this->cookieManager->setDefaultCookieJar($jar);
        return $this;
    }

    /**
     * Create and use a file-based cookie jar for all requests.
     */
    public function withGlobalFileCookieJar(?string $filename = null, bool $includeSessionCookies = true): self
    {
        if ($filename === null) {
            $filename = $this->cookieManager->createTempCookieFile();
        }

        $jar = $this->cookieManager->createFileCookieJar($filename, $includeSessionCookies);
        return $this;
    }

    public function enableNetworkSimulation(array $settings = []): self
    {
        $this->networkSimulator->enable($settings);

        return $this;
    }

    public function setAutoTempFileManagement(bool $enabled): self
    {
        $this->fileManager->setAutoManagement($enabled);

        return $this;
    }

    public function setStrictMatching(bool $strict): self
    {
        $this->globalSettings['strict_matching'] = $strict;

        return $this;
    }

    public function setRecordRequests(bool $enabled): self
    {
        $this->globalSettings['record_requests'] = $enabled;

        return $this;
    }

    public function setAllowPassthrough(bool $allow): self
    {
        $this->globalSettings['allow_passthrough'] = $allow;

        return $this;
    }

    /**
     * Override the main sendRequest method used by the Request builder.
     * This is the primary entry point for all Request builder requests.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if (!isset($curlOptions['_cookie_jar'])) {
            $this->cookieManager->applyCookiesToCurlOptions($curlOptions, $url);
        }

        if ($cacheConfig !== null && ($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') === 'GET') {
            $cache = $cacheConfig->cache ?? self::getTestingDefaultCache();
            $cacheKey = self::generateCacheKey($url);
            $cachedItem = $cache->get($cacheKey);

            if ($cachedItem !== null && is_array($cachedItem) && time() < ($cachedItem['expires_at'] ?? 0)) {
                $this->recordRequest('GET (FROM CACHE)', $url, $curlOptions);
                return Promise::resolved(new Response(
                    $cachedItem['body'],
                    $cachedItem['status'],
                    $cachedItem['headers']
                ));
            }
        }

        $promise = $this->executeMockedRequest($url, $curlOptions, $retryConfig);

        if ($promise instanceof PromiseInterface) {
            $promise = $promise->then(function ($response) use ($url) {
                if ($response instanceof Response) {
                    $this->cookieManager->processSetCookieHeaders($response->getHeaders());
                }
                return $response;
            });
        }

        if ($cacheConfig !== null) {
            return $promise->then(function ($response) use ($cacheConfig, $url) {
                if ($response instanceof Response && $response->ok()) {
                    $cache = $cacheConfig->cache ?? self::getTestingDefaultCache();
                    $cacheKey = self::generateCacheKey($url);
                    $expiry = time() + $cacheConfig->ttlSeconds;
                    $cache->set($cacheKey, [
                        'body' => $response->body(),
                        'status' => $response->status(),
                        'headers' => $response->headers(),
                        'expires_at' => $expiry,
                    ], $cacheConfig->ttlSeconds);
                }
                return $response;
            });
        }

        return $promise;
    }

    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        $method = strtoupper($options['method'] ?? 'GET');
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $retryConfig = $this->extractRetryConfigFromOptions($options);

        if ($retryConfig !== null) {
            return $this->executeWithMockRetry($url, $options, $retryConfig, $method);
        }

        $this->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            $mock = $match['mock'];

            if (!$mock->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            if (isset($options['download'])) {
                $destination = $options['download'];
                return $this->responseFactory->createMockedDownload($mock, $destination, $this->fileManager);
            }

            if (isset($options['stream']) && $options['stream'] === true) {
                $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;
                return $this->responseFactory->createMockedStream($mock, $onChunk, [$this, 'createStream']);
            }

            return $this->responseFactory->createMockedResponse($mock);
        }

        if ($this->globalSettings['strict_matching'] && !$this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for: {$method} {$url}");
        }

        return parent::fetch($url, $options);
    }

    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $options['stream'] = true;
        if ($onChunk) {
            $options['on_chunk'] = $onChunk;
        }
        return $this->fetch($url, $options);
    }

    public function download(string $url, ?string $destination = null, array $options = []): CancellablePromiseInterface
    {
        if ($destination === null) {
            $destination = $this->fileManager->createTempFile(
                'download_' . uniqid() . '.tmp'
            );
        } else {
            $this->fileManager->trackFile($destination);
        }

        $options['download'] = $destination;
        return $this->fetch($url, $options);
    }

    public static function getTempPath(?string $filename = null): string
    {
        return FileManager::getTempPath($filename);
    }

    public function createTempDirectory(string $prefix = 'http_test_'): string
    {
        return $this->fileManager->createTempDirectory($prefix);
    }

    public function createTempFile(?string $filename = null, string $content = ''): string
    {
        return $this->fileManager->createTempFile($filename, $content);
    }

    public function assertRequestMade(string $method, string $url, array $options = []): void
    {
        foreach ($this->requestHistory as $request) {
            if ($this->requestMatcher->matchesRequest($request, $method, $url, $options)) {
                return;
            }
        }

        throw new Exception("Expected request not found: {$method} {$url}");
    }

    public function assertNoRequestsMade(): void
    {
        if (! empty($this->requestHistory)) {
            throw new Exception('Expected no requests, but ' . count($this->requestHistory) . ' were made');
        }
    }

    public function assertRequestCount(int $expected): void
    {
        $actual = count($this->requestHistory);
        if ($actual !== $expected) {
            throw new Exception("Expected {$expected} requests, but {$actual} were made");
        }
    }

    /**
     * Assert that a cookie was sent in the most recent request.
     */
    public function assertCookieSent(string $name): void
    {
        if (empty($this->requestHistory)) {
            throw new Exception('No requests have been made');
        }

        $lastRequest = end($this->requestHistory);
        $this->cookieManager->assertCookieSent($name, $lastRequest->options);
    }

    /**
     * Assert that a cookie exists in the default cookie jar.
     */
    public function assertCookieExists(string $name): void
    {
        $this->cookieManager->assertCookieExists($name);
    }

    /**
     * Assert that a cookie has a specific value.
     */
    public function assertCookieValue(string $name, string $expectedValue): void
    {
        $this->cookieManager->assertCookieValue($name, $expectedValue);
    }

    public function getRequestHistory(): array
    {
        return $this->requestHistory;
    }

    public function reset(): void
    {
        $this->mockedRequests = [];
        $this->requestHistory = [];
        $this->fileManager->cleanup();
        $this->cookieManager->cleanup();
    }

    private function executeMockedRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

        if ($retryConfig !== null && $match !== null) {
            return $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method);
        }

        $this->recordRequest($method, $url, $curlOptions);

        if ($match !== null) {
            if (!$match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }
            return $this->responseFactory->createMockedResponse($match['mock']);
        }

        if ($this->globalSettings['strict_matching'] && !$this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for: {$method} {$url}");
        }

        return parent::sendRequest($url, $curlOptions, null, $retryConfig); // Pass null for cache config to avoid loop
    }

    private static function getTestingDefaultCache(): CacheInterface
    {
        static $cache = null;
        if ($cache === null) {
            $cache = new Psr16Cache(new ArrayAdapter());
        }
        return $cache;
    }

    /**
     * Execute a mocked request with retry logic.
     * This method is responsible for consuming one mock per attempt.
     */
    private function executeWithMockRetry(string $url, array $options, RetryConfig $retryConfig, string $method): PromiseInterface
    {
        $finalPromise = new CancellablePromise();
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        $retryPromise = $this->responseFactory->createRetryableMockedResponse(
            $retryConfig,
            function (int $attemptNumber) use ($method, $url, $curlOptions) {
                $this->recordRequest($method, $url, $curlOptions);
                $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);
                if ($match === null) throw new Exception("No mock for attempt #{$attemptNumber}: {$method} {$url}");
                if (!$match['mock']->isPersistent()) array_splice($this->mockedRequests, $match['index'], 1);
                return $match['mock'];
            }
        );

        $retryPromise->then(
            function ($successfulResponse) use ($options, $finalPromise) {
                if (isset($options['download'])) {
                    $destPath = $options['download'] ?? $this->fileManager->createTempFile();
                    file_put_contents($destPath, $successfulResponse->body());
                    $result = ['file' => $destPath, 'status' => $successfulResponse->status(), 'headers' => $successfulResponse->headers(), 'size' => strlen($successfulResponse->body())];
                    $finalPromise->resolve($result);
                } elseif (isset($options['stream']) && $options['stream'] === true) {
                    $onChunk = $options['on_chunk'] ?? null;
                    $body = $successfulResponse->body();
                    if ($onChunk) $onChunk($body);
                    $finalPromise->resolve(new StreamingResponse($this->createStream($body), $successfulResponse->status(), $successfulResponse->headers()));
                } else {
                    $finalPromise->resolve($successfulResponse);
                }
            },
            fn($reason) => $finalPromise->reject($reason)
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn() => $retryPromise->cancel());
        }

        return $finalPromise;
    }

    private function extractRetryConfigFromOptions(array $options): ?RetryConfig
    {
        if (! isset($options['retry'])) {
            return null;
        }
        $retry = $options['retry'];
        if ($retry === true) {
            return new RetryConfig;
        }
        if ($retry instanceof RetryConfig) {
            return $retry;
        }
        if (is_array($retry)) {
            return new RetryConfig(
                maxRetries: (int) ($retry['max_retries'] ?? 3),
                baseDelay: (float) ($retry['base_delay'] ?? 1.0),
                maxDelay: (float) ($retry['max_delay'] ?? 60.0),
                backoffMultiplier: (float) ($retry['backoff_multiplier'] ?? 2.0),
                jitter: (bool) ($retry['jitter'] ?? true),
                retryableStatusCodes: $retry['retryable_status_codes'] ?? [408, 429, 500, 502, 503, 504],
                retryableExceptions: $retry['retryable_exceptions'] ?? ['cURL error', 'timeout', 'connection failed']
            );
        }

        return null;
    }

    private function recordRequest(string $method, string $url, array $options): void
    {
        if (! $this->globalSettings['record_requests']) {
            return;
        }
        $this->requestHistory[] = new RecordedRequest($method, $url, $options, microtime(true));
    }
}
