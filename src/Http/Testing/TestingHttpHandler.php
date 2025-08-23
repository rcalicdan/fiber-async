<?php

namespace Rcalicdan\FiberAsync\Http\Testing;

use Exception;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Http\Testing\Services\FileManager;
use Rcalicdan\FiberAsync\Http\Testing\Services\NetworkSimulator;
use Rcalicdan\FiberAsync\Http\Testing\Services\RequestMatcher;
use Rcalicdan\FiberAsync\Http\Testing\Services\ResponseFactory;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

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

    private ?string $recordingFile = null;

    private FileManager $fileManager;
    private NetworkSimulator $networkSimulator;
    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;

    public function __construct()
    {
        parent::__construct();
        $this->fileManager = new FileManager;
        $this->networkSimulator = new NetworkSimulator;
        $this->requestMatcher = new RequestMatcher;
        $this->responseFactory = new ResponseFactory($this->networkSimulator);
    }

    public function mock(string $method = '*'): MockRequestBuilder
    {
        return new MockRequestBuilder($this, $method);
    }

    public function addMockedRequest(MockedRequest $request): void
    {
        $this->mockedRequests[] = $request;
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
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

        if ($retryConfig !== null && $match !== null) {
            return $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method);
        }

        $this->recordRequest($method, $url, $curlOptions);

        if ($match !== null) {
            if (! $match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedResponse($match['mock']);
        }

        if ($this->globalSettings['strict_matching'] && ! $this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for: {$method} {$url}");
        }

        return parent::sendRequest($url, $curlOptions, $cacheConfig, $retryConfig);
    }

    /**
     * Execute a mocked request with retry logic.
     * This method is responsible for consuming one mock per attempt.
     */
    private function executeWithMockRetry(string $url, array $curlOptions, RetryConfig $retryConfig, string $method): PromiseInterface
    {
        return $this->responseFactory->createRetryableMockedResponse(
            $retryConfig,
            function (int $attemptNumber) use ($method, $url, $curlOptions) {
                $this->recordRequest($method, $url, $curlOptions);

                $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

                if ($match === null) {
                    throw new Exception("No mock available for attempt #{$attemptNumber}: {$method} {$url}");
                }

                $mock = $match['mock'];

                if (! $mock->isPersistent()) {
                    array_splice($this->mockedRequests, $match['index'], 1);
                }

                return $mock;
            }
        );
    }

    /**
     * Override fetch to intercept direct fetch calls and apply mocking/retry logic.
     * This is the entry point for Http::fetch().
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        $method = strtoupper($options['method'] ?? 'GET');
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $retryConfig = $this->extractRetryConfigFromOptions($options);

        if ($retryConfig !== null) {
            return $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method);
        }

        // For non-retry requests, record the single attempt here.
        $this->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            $mock = $match['mock'];

            if (! $mock->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            if (isset($options['download']) || isset($options['save_to'])) {
                $destination = $options['download'] ?? $options['save_to'];

                return $this->responseFactory->createMockedDownload($mock, $destination, $this->fileManager);
            }

            if (isset($options['stream']) && $options['stream'] === true) {
                $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;

                return $this->responseFactory->createMockedStream($mock, $onChunk, [$this, 'createStream']);
            }

            return $this->responseFactory->createMockedResponse($mock);
        }

        if ($this->globalSettings['strict_matching'] && ! $this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for: {$method} {$url}");
        }

        return parent::fetch($url, $options);
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

    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $method = 'GET';
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $retryConfig = $options['retry'] ?? null;

        if ($retryConfig instanceof RetryConfig) {
            $finalPromise = new CancellablePromise;

            $retryPromise = $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method);

            $retryPromise->then(
                function ($successfulResponse) use ($onChunk, $finalPromise) {
                    $body = $successfulResponse->body();
                    if ($onChunk !== null) {
                        $onChunk($body);
                    }
                    $streamingResponse = new StreamingResponse($this->createStream($body), $successfulResponse->status(), $successfulResponse->headers());
                    $finalPromise->resolve($streamingResponse);
                },
                fn ($reason) => $finalPromise->reject($reason) // If inner promise fails, reject the outer one.
            );

            if ($retryPromise instanceof CancellablePromiseInterface) {
                $finalPromise->setCancelHandler(fn () => $retryPromise->cancel());
            }

            return $finalPromise;
        }

        $this->recordRequest($method, $url, $curlOptions);
        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);
        if ($match !== null) {
            if (! $match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedStream($match['mock'], $onChunk, [$this, 'createStream']);
        }

        if ($this->globalSettings['strict_matching'] && ! $this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for stream: {$method} {$url}");
        }

        return parent::stream($url, $options, $onChunk);
    }

    public function download(string $url, ?string $destination = null, array $options = []): CancellablePromiseInterface
    {
        $method = 'GET';
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $retryConfig = $options['retry'] ?? null;

        if ($retryConfig instanceof RetryConfig) {
            $finalPromise = new CancellablePromise;

            $retryPromise = $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method);

            $retryPromise->then(
                function ($successfulResponse) use ($destination, $finalPromise) {
                    $destPath = $destination ?? $this->fileManager->createTempFile();
                    file_put_contents($destPath, $successfulResponse->body());
                    $result = [
                        'file' => $destPath,
                        'status' => $successfulResponse->status(),
                        'headers' => $successfulResponse->headers(),
                        'size' => strlen($successfulResponse->body()),
                    ];
                    $finalPromise->resolve($result);
                },
                fn ($reason) => $finalPromise->reject($reason)
            );

            if ($retryPromise instanceof CancellablePromiseInterface) {
                $finalPromise->setCancelHandler(fn () => $retryPromise->cancel());
            }

            return $finalPromise;
        }

        $this->recordRequest($method, $url, $curlOptions);
        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);
        if ($match !== null) {
            if (! $match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }
            $destPath = $destination ?? $this->fileManager->createTempFile();

            return $this->responseFactory->createMockedDownload($match['mock'], $destPath, $this->fileManager);
        }
        if ($this->globalSettings['strict_matching'] && ! $this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for download: {$method} {$url}");
        }
        if ($destination === null) {
            throw new \ArgumentCountError('A destination path is required for non-mocked downloads.');
        }

        return parent::download($url, $destination, $options);
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
            throw new Exception('Expected no requests, but '.count($this->requestHistory).' were made');
        }
    }

    public function assertRequestCount(int $expected): void
    {
        $actual = count($this->requestHistory);
        if ($actual !== $expected) {
            throw new Exception("Expected {$expected} requests, but {$actual} were made");
        }
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
    }

    private function recordRequest(string $method, string $url, array $options): void
    {
        if (! $this->globalSettings['record_requests']) {
            return;
        }
        $this->requestHistory[] = new RecordedRequest($method, $url, $options, microtime(true));
    }
}
