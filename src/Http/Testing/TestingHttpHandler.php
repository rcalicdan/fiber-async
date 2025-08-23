<?php

namespace Rcalicdan\FiberAsync\Http\Testing;

use Exception;
use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\Testing\Services\FileManager;
use Rcalicdan\FiberAsync\Http\Testing\Services\NetworkSimulator;
use Rcalicdan\FiberAsync\Http\Testing\Services\RequestMatcher;
use Rcalicdan\FiberAsync\Http\Testing\Services\ResponseFactory;
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

        $this->fileManager = new FileManager();
        $this->networkSimulator = new NetworkSimulator();
        $this->requestMatcher = new RequestMatcher();
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

    // === CORE OVERRIDE METHODS FOR REQUEST BUILDER INTEGRATION ===

    /**
     * Override the main sendRequest method used by the Request builder
     * This is the primary entry point for all Request builder requests
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        $this->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            $mock = $match['mock'];

            if (!$mock->isPersistent() && (!$mock->shouldFail() || !$mock->isRetryableFailure())) {
                array_splice($this->mockedRequests, $match['index'], 1);
            } elseif (!$mock->isPersistent() && $mock->isRetryableFailure()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedResponse($mock);
        }

        if ($this->globalSettings['strict_matching'] && !$this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for: {$method} {$url}");
        }

        return parent::sendRequest($url, $curlOptions, $cacheConfig, $retryConfig);
    }

    /**
     * Override fetch to intercept direct fetch calls
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        $method = strtoupper($options['method'] ?? 'GET');
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        $this->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            if (!$match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            // Handle different response types based on options
            if (isset($options['download']) || isset($options['save_to'])) {
                $destination = $options['download'] ?? $options['save_to'];
                return $this->responseFactory->createMockedDownload($match['mock'], $destination, $this->fileManager);
            }

            if (isset($options['stream']) && $options['stream'] === true) {
                $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;
                return $this->responseFactory->createMockedStream($match['mock'], $onChunk, [$this, 'createStream']);
            }

            return $this->responseFactory->createMockedResponse($match['mock']);
        }

        // No mock found - handle based on settings
        if ($this->globalSettings['strict_matching'] && !$this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for: {$method} {$url}");
        }

        // Pass through to real fetch with all retry/timeout logic intact
        return parent::fetch($url, $options);
    }

    /**
     * Override stream to intercept streaming requests
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $this->recordRequest('GET', $url, $options);
        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, 'GET', $url, $options);

        if ($match !== null) {
            if (!$match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedStream($match['mock'], $onChunk, [$this, 'createStream']);
        }

        if ($this->globalSettings['strict_matching'] && !$this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for stream: {$url}");
        }

        return parent::stream($url, $options, $onChunk);
    }

    /**
     * Override download to intercept download requests
     */
    public function download(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $this->recordRequest('GET', $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($this->mockedRequests, 'GET', $url, $curlOptions);

        if ($match !== null) {
            if (!$match['mock']->isPersistent()) {
                array_splice($this->mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedDownload($match['mock'], $destination, $this->fileManager);
        }

        if ($this->globalSettings['strict_matching'] && !$this->globalSettings['allow_passthrough']) {
            throw new Exception("No mock found for download: {$url}");
        }

        return parent::download($url, $destination, $options);
    }

    // === UTILITY METHODS ===

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

    public function startRecording(string $filename): self
    {
        $this->recordingFile = $filename;
        return $this;
    }

    public function loadRecording(string $filename): self
    {
        if (!file_exists($filename)) {
            throw new Exception("Recording file not found: {$filename}");
        }

        $recorded = json_decode(file_get_contents($filename), true);
        foreach ($recorded as $item) {
            $this->addMockedRequest(MockedRequest::fromArray($item));
        }

        return $this;
    }

    // === ASSERTION METHODS ===

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
        if (!empty($this->requestHistory)) {
            throw new Exception("Expected no requests, but " . count($this->requestHistory) . " were made");
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

    // === PRIVATE HELPER METHODS ===

    private function recordRequest(string $method, string $url, array $options): void
    {
        if (!$this->globalSettings['record_requests']) {
            return;
        }

        $recorded = new RecordedRequest($method, $url, $options, microtime(true));
        $this->requestHistory[] = $recorded;

        if ($this->recordingFile) {
            $this->saveRecording();
        }
    }

    private function saveRecording(): void
    {
        if (!$this->recordingFile) {
            return;
        }

        $data = array_map(fn($mock) => $mock->toArray(), $this->mockedRequests);
        file_put_contents($this->recordingFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function normalizeFetchOptions(string $url, array $options): array
    {
        return parent::normalizeFetchOptions($url, $options);
    }
}
