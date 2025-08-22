<?php

namespace Rcalicdan\FiberAsync\Http\Testing;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

/**
 * Robust HTTP testing handler with comprehensive mocking capabilities.
 * 
 * Features:
 * - Request matching with multiple criteria
 * - Response queuing and sequencing
 * - Network condition simulation (delays, failures, timeouts)
 * - Detailed request history and assertions
 * - Streaming and download support
 * - Realistic error simulation
 * - Request/response recording and playback
 * - Automatic temp file management
 */
class TestingHttpHandler extends HttpHandler
{
    /** @var array<MockedRequest> */
    private array $mockedRequests = [];

    /** @var array<RecordedRequest> */
    private array $requestHistory = [];

    /** @var array<string, mixed> */
    private array $globalSettings = [
        'record_requests' => true,
        'strict_matching' => false,
        'default_delay' => 0,
        'failure_rate' => 0.0,
        'timeout_rate' => 0.0,
    ];

    private bool $networkSimulationEnabled = false;
    private ?string $recordingFile = null;

    /** @var array<string> */
    private array $createdFiles = [];

    /** @var array<string> */
    private array $createdDirectories = [];

    /** @var bool */
    private bool $autoManageTempFiles = true;

    /**
     * Create a new testing handler instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Mock a request with detailed matching criteria.
     */
    public function mock(string $method = '*'): MockRequestBuilder
    {
        return new MockRequestBuilder($this, $method);
    }

    /**
     * Add a mocked request to the queue.
     * 
     * @internal Used by MockRequestBuilder
     */
    public function addMockedRequest(MockedRequest $request): void
    {
        $this->mockedRequests[] = $request;
    }

    /**
     * Enable network condition simulation.
     */
    public function enableNetworkSimulation(array $settings = []): self
    {
        $this->networkSimulationEnabled = true;
        $this->globalSettings = array_merge($this->globalSettings, $settings);
        return $this;
    }

    /**
     * Enable or disable automatic temp file management.
     */
    public function setAutoTempFileManagement(bool $enabled): self
    {
        $this->autoManageTempFiles = $enabled;
        return $this;
    }

    /**
     * Start recording requests to a file for playback later.
     */
    public function startRecording(string $filename): self
    {
        $this->recordingFile = $filename;
        return $this;
    }

    /**
     * Load and replay recorded requests from a file.
     */
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

    /**
     * Assert that a specific request was made.
     */
    public function assertRequestMade(string $method, string $url, array $options = []): void
    {
        $found = false;
        foreach ($this->requestHistory as $request) {
            if ($this->matchesRequest($request, $method, $url, $options)) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new Exception("Expected request not found: {$method} {$url}");
        }
    }

    /**
     * Assert that no requests were made.
     */
    public function assertNoRequestsMade(): void
    {
        if (!empty($this->requestHistory)) {
            throw new Exception("Expected no requests, but " . count($this->requestHistory) . " were made");
        }
    }

    /**
     * Assert a specific number of requests were made.
     */
    public function assertRequestCount(int $expected): void
    {
        $actual = count($this->requestHistory);
        if ($actual !== $expected) {
            throw new Exception("Expected {$expected} requests, but {$actual} were made");
        }
    }

    /**
     * Get all recorded requests.
     * 
     * @return array<RecordedRequest>
     */
    public function getRequestHistory(): array
    {
        return $this->requestHistory;
    }

    /**
     * Get a cross-platform temporary file path.
     * If no filename provided, generates a unique one.
     */
    public static function getTempPath(?string $filename = null): string
    {
        $tempDir = sys_get_temp_dir();

        if ($filename === null) {
            $filename = 'http_test_' . uniqid() . '.tmp';
        }

        return $tempDir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Create a temporary directory for testing.
     */
    public function createTempDirectory(string $prefix = 'http_test_'): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            throw new Exception("Cannot create temp directory: {$tempDir}");
        }

        // Track for cleanup
        if ($this->autoManageTempFiles) {
            $this->createdDirectories[] = $tempDir;
        }
        return $tempDir;
    }

    /**
     * Create a temporary file with automatic cleanup tracking.
     * If no filename provided, generates a unique one.
     */
    public function createTempFile(?string $filename = null, string $content = ''): string
    {
        if ($filename === null) {
            $filename = 'http_test_' . uniqid() . '.tmp';
        }

        $filePath = self::getTempPath($filename);

        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new Exception("Cannot create directory: {$directory}");
            }
            if ($this->autoManageTempFiles) {
                $this->createdDirectories[] = $directory;
            }
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new Exception("Cannot create temp file: {$filePath}");
        }

        // Track for cleanup if auto-management is enabled
        if ($this->autoManageTempFiles) {
            $this->createdFiles[] = $filePath;
        }

        return $filePath;
    }

    /**
     * Clear all mocked requests, history, and cleanup temp files/directories.
     */
    public function reset(): void
    {
        $this->mockedRequests = [];
        $this->requestHistory = [];

        // Clean up created files and directories
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->createdDirectories) as $dir) {
            if (is_dir($dir)) {
                // Remove directory contents first
                $this->removeDirectoryContents($dir);
                rmdir($dir);
            }
        }

        $this->createdFiles = [];
        $this->createdDirectories = [];
    }

    /**
     * Override download method to automatically handle temp paths and cleanup.
     */
    public function download(string $url, ?string $destination = null, array $options = []): CancellablePromiseInterface
    {
        // If no destination provided, create automatic temp file
        if ($destination === null) {
            $urlPath = parse_url($url, PHP_URL_PATH);
            $filename = $urlPath ? basename($urlPath) : 'download_' . uniqid() . '.tmp';
            $destination = $this->createTempFile($filename);
        }

        $this->recordRequest('GET', $url, $options);

        $mock = $this->findMatchingMock('GET', $url, $options);

        if ($mock === null) {
            if ($this->globalSettings['strict_matching']) {
                throw new Exception("No mock found for download: {$url}");
            }
            return parent::download($url, $destination, $options);
        }

        return $this->createMockedDownload($mock, $destination);
    }

    /**
     * Get the next matching mocked request.
     */
    private function findMatchingMock(string $method, string $url, array $options): ?MockedRequest
    {
        foreach ($this->mockedRequests as $index => $mock) {
            if ($mock->matches($method, $url, $options)) {
                // Remove one-time mocks after use
                if (!$mock->isPersistent()) {
                    array_splice($this->mockedRequests, $index, 1);
                }
                return $mock;
            }
        }
        return null;
    }

    /**
     * Record a request for assertion purposes.
     */
    private function recordRequest(string $method, string $url, array $options): void
    {
        if (!$this->globalSettings['record_requests']) {
            return;
        }

        $recorded = new RecordedRequest($method, $url, $options, microtime(true));
        $this->requestHistory[] = $recorded;

        // Save to file if recording
        if ($this->recordingFile) {
            $this->saveRecording();
        }
    }

    /**
     * Simulate network conditions if enabled.
     */
    private function simulateNetworkConditions(): void
    {
        if (!$this->networkSimulationEnabled) {
            return;
        }

        // Simulate random failures
        if (mt_rand() / mt_getrandmax() < $this->globalSettings['failure_rate']) {
            throw new HttpException("Simulated network failure");
        }

        // Simulate timeouts
        if (mt_rand() / mt_getrandmax() < $this->globalSettings['timeout_rate']) {
            throw new HttpException("Simulated timeout");
        }
    }

    /**
     * Override the basic fetch to intercept requests.
     */
    public function sendRequest(string $url, array $curlOptions, $cacheConfig = null, $retryConfig = null): PromiseInterface
    {
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';

        $this->recordRequest($method, $url, $curlOptions);

        $mock = $this->findMatchingMock($method, $url, $curlOptions);

        if ($mock === null) {
            if ($this->globalSettings['strict_matching']) {
                throw new Exception("No mock found for: {$method} {$url}");
            }
            // Fall back to real request in non-strict mode
            return parent::sendRequest($url, $curlOptions, $cacheConfig, $retryConfig);
        }

        return $this->createMockedResponse($mock);
    }

    /**
     * Create a mocked response promise.
     */
    private function createMockedResponse(MockedRequest $mock): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();

        // Simulate async behavior
        EventLoop::getInstance()->addTimer($mock->getDelay(), function () use ($promise, $mock) {
            try {
                $this->simulateNetworkConditions();

                if ($mock->shouldFail()) {
                    $promise->reject(new HttpException($mock->getError()));
                } else {
                    $response = new Response(
                        $mock->getBody(),
                        $mock->getStatusCode(),
                        $mock->getHeaders()
                    );
                    $promise->resolve($response);
                }
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Override stream method for testing.
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $this->recordRequest('GET', $url, $options);

        $mock = $this->findMatchingMock('GET', $url, $options);

        if ($mock === null) {
            if ($this->globalSettings['strict_matching']) {
                throw new Exception("No mock found for stream: {$url}");
            }
            return parent::stream($url, $options, $onChunk);
        }

        return $this->createMockedStream($mock, $onChunk);
    }

    /**
     * Create a mocked streaming response.
     */
    private function createMockedStream(MockedRequest $mock, ?callable $onChunk): CancellablePromiseInterface
    {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise();

        EventLoop::getInstance()->addTimer($mock->getDelay(), function () use ($promise, $mock, $onChunk) {
            try {
                $this->simulateNetworkConditions();

                if ($mock->shouldFail()) {
                    $promise->reject(new HttpException($mock->getError()));
                } else {
                    // Simulate chunk processing if callback provided
                    if ($onChunk !== null) {
                        $body = $mock->getBody();
                        $chunkSize = 1024; // 1KB chunks
                        for ($i = 0; $i < strlen($body); $i += $chunkSize) {
                            $chunk = substr($body, $i, $chunkSize);
                            $onChunk($chunk);
                        }
                    }

                    // Create a temp stream with the body
                    $stream = $this->createStream($mock->getBody());
                    $streamingResponse = new StreamingResponse(
                        $stream,
                        $mock->getStatusCode(),
                        $mock->getHeaders()
                    );

                    $promise->resolve($streamingResponse);
                }
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Create a mocked download response with automatic temp file handling.
     */
    private function createMockedDownload(MockedRequest $mock, string $destination): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array> $promise */
        $promise = new CancellablePromise();

        EventLoop::getInstance()->addTimer($mock->getDelay(), function () use ($promise, $mock, $destination) {
            try {
                $this->simulateNetworkConditions();

                if ($mock->shouldFail()) {
                    $promise->reject(new Exception($mock->getError()));
                } else {
                    // Ensure directory exists
                    $directory = dirname($destination);
                    if (!is_dir($directory)) {
                        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                            $promise->reject(new Exception("Cannot create directory: {$directory}"));
                            return;
                        }
                        // Track created directory for cleanup
                        if ($this->autoManageTempFiles) {
                            $this->createdDirectories[] = $directory;
                        }
                    }

                    // Write mock data to file
                    $bytesWritten = file_put_contents($destination, $mock->getBody());
                    if ($bytesWritten === false) {
                        $promise->reject(new Exception("Cannot write to file: {$destination}"));
                        return;
                    }

                    // Track created file for cleanup (only if not already tracked)
                    if ($this->autoManageTempFiles && !in_array($destination, $this->createdFiles)) {
                        $this->createdFiles[] = $destination;
                    }

                    $promise->resolve([
                        'file' => $destination,
                        'status' => $mock->getStatusCode(),
                        'headers' => $mock->getHeaders(),
                        'size' => strlen($mock->getBody()),
                        'protocol_version' => '2.0'
                    ]);
                }
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Check if a recorded request matches criteria.
     */
    private function matchesRequest(RecordedRequest $request, string $method, string $url, array $options = []): bool
    {
        if ($request->method !== $method && $method !== '*') {
            return false;
        }

        if (!fnmatch($url, $request->url) && $request->url !== $url) {
            return false;
        }

        // Add more matching logic as needed
        return true;
    }

    /**
     * Save current session to recording file.
     */
    private function saveRecording(): void
    {
        if (!$this->recordingFile) {
            return;
        }

        $data = array_map(fn($mock) => $mock->toArray(), $this->mockedRequests);
        file_put_contents($this->recordingFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Helper method to remove directory contents recursively.
     */
    private function removeDirectoryContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectoryContents($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }
}

/**
 * Builder for creating mocked requests with fluent interface.
 */
class MockRequestBuilder
{
    private TestingHttpHandler $handler;
    private MockedRequest $request;

    public function __construct(TestingHttpHandler $handler, string $method = '*')
    {
        $this->handler = $handler;
        $this->request = new MockedRequest($method);
    }

    /**
     * Set the URL pattern to match.
     */
    public function url(string $pattern): self
    {
        $this->request->setUrlPattern($pattern);
        return $this;
    }

    /**
     * Match requests with specific headers.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->request->addHeaderMatcher($name, $value);
        return $this;
    }

    /**
     * Match requests with specific body content.
     */
    public function withBody(string $pattern): self
    {
        $this->request->setBodyMatcher($pattern);
        return $this;
    }

    /**
     * Match requests with specific JSON data.
     */
    public function withJson(array $data): self
    {
        $this->request->setJsonMatcher($data);
        return $this;
    }

    /**
     * Set the response status code.
     */
    public function respondWith(int $status = 200): self
    {
        $this->request->setStatusCode($status);
        return $this;
    }

    /**
     * Set the response body.
     */
    public function body(string $body): self
    {
        $this->request->setBody($body);
        return $this;
    }

    /**
     * Set response as JSON.
     */
    public function json(array $data): self
    {
        $this->request->setBody(json_encode($data));
        $this->request->addResponseHeader('Content-Type', 'application/json');
        return $this;
    }

    /**
     * Add response headers.
     */
    public function header(string $name, string $value): self
    {
        $this->request->addResponseHeader($name, $value);
        return $this;
    }

    /**
     * Simulate network delay.
     */
    public function delay(float $seconds): self
    {
        $this->request->setDelay($seconds);
        return $this;
    }

    /**
     * Make this request fail with an error.
     */
    public function fail(string $error = "Mocked request failure"): self
    {
        $this->request->setError($error);
        return $this;
    }

    /**
     * Make this mock persistent (can be used multiple times).
     */
    public function persistent(): self
    {
        $this->request->setPersistent(true);
        return $this;
    }

    /**
     * Mock a file download with automatic temp file handling.
     * 
     * @param string $content File content to mock
     * @param string|null $filename Optional filename (will generate if null)
     * @param string $contentType MIME type
     * @return self
     */
    public function downloadFile(string $content, ?string $filename = null, string $contentType = 'application/octet-stream'): self
    {
        $this->request->setBody($content);
        $this->request->addResponseHeader('Content-Type', $contentType);
        $this->request->addResponseHeader('Content-Length', (string)strlen($content));

        if ($filename !== null) {
            $this->request->addResponseHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        return $this;
    }

    /**
     * Mock a large file download for testing.
     */
    public function downloadLargeFile(int $sizeInKB = 100, ?string $filename = null): self
    {
        $content = str_repeat('MOCK_FILE_DATA_', $sizeInKB * 64); // Approximately 1KB per iteration
        return $this->downloadFile($content, $filename, 'application/octet-stream');
    }

    /**
     * Register the mock with the handler.
     */
    public function register(): void
    {
        $this->handler->addMockedRequest($this->request);
    }
}

/**
 * Represents a mocked HTTP request with matching criteria and response data.
 */
class MockedRequest
{
    private string $method;
    private ?string $urlPattern = null;
    private array $headerMatchers = [];
    private ?string $bodyMatcher = null;
    private ?array $jsonMatcher = null;

    private int $statusCode = 200;
    private string $body = '';
    private array $headers = [];
    private float $delay = 0;
    private ?string $error = null;
    private bool $persistent = false;

    public function __construct(string $method = '*')
    {
        $this->method = $method;
    }

    public function setUrlPattern(string $pattern): void
    {
        $this->urlPattern = $pattern;
    }

    public function addHeaderMatcher(string $name, string $value): void
    {
        $this->headerMatchers[strtolower($name)] = $value;
    }

    public function setBodyMatcher(string $pattern): void
    {
        $this->bodyMatcher = $pattern;
    }

    public function setJsonMatcher(array $data): void
    {
        $this->jsonMatcher = $data;
    }

    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function addResponseHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function setDelay(float $seconds): void
    {
        $this->delay = $seconds;
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }

    public function setPersistent(bool $persistent): void
    {
        $this->persistent = $persistent;
    }

    /**
     * Check if this mock matches the given request.
     */
    public function matches(string $method, string $url, array $options): bool
    {
        // Check method
        if ($this->method !== '*' && strtoupper($this->method) !== strtoupper($method)) {
            return false;
        }

        // Check URL pattern
        if ($this->urlPattern !== null && !fnmatch($this->urlPattern, $url)) {
            return false;
        }

        // Check headers
        if (!empty($this->headerMatchers)) {
            $requestHeaders = $this->extractHeaders($options);
            foreach ($this->headerMatchers as $name => $expectedValue) {
                $actualValue = $requestHeaders[strtolower($name)] ?? null;
                if ($actualValue !== $expectedValue) {
                    return false;
                }
            }
        }

        // Check body
        if ($this->bodyMatcher !== null) {
            $body = $options[CURLOPT_POSTFIELDS] ?? '';
            if (!fnmatch($this->bodyMatcher, $body)) {
                return false;
            }
        }

        // Check JSON
        if ($this->jsonMatcher !== null) {
            $body = $options[CURLOPT_POSTFIELDS] ?? '';
            $decoded = json_decode($body, true);
            if ($decoded !== $this->jsonMatcher) {
                return false;
            }
        }

        return true;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    public function getBody(): string
    {
        return $this->body;
    }
    public function getHeaders(): array
    {
        return $this->headers;
    }
    public function getDelay(): float
    {
        return $this->delay;
    }
    public function getError(): ?string
    {
        return $this->error;
    }
    public function shouldFail(): bool
    {
        return $this->error !== null;
    }
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    private function extractHeaders(array $options): array
    {
        $headers = [];
        if (isset($options[CURLOPT_HTTPHEADER])) {
            foreach ($options[CURLOPT_HTTPHEADER] as $header) {
                if (strpos($header, ':') !== false) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
        }
        return $headers;
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'urlPattern' => $this->urlPattern,
            'headerMatchers' => $this->headerMatchers,
            'bodyMatcher' => $this->bodyMatcher,
            'jsonMatcher' => $this->jsonMatcher,
            'statusCode' => $this->statusCode,
            'body' => $this->body,
            'headers' => $this->headers,
            'delay' => $this->delay,
            'error' => $this->error,
            'persistent' => $this->persistent,
        ];
    }

    public static function fromArray(array $data): self
    {
        $request = new self($data['method']);
        $request->urlPattern = $data['urlPattern'];
        $request->headerMatchers = $data['headerMatchers'] ?? [];
        $request->bodyMatcher = $data['bodyMatcher'];
        $request->jsonMatcher = $data['jsonMatcher'];
        $request->statusCode = $data['statusCode'];
        $request->body = $data['body'];
        $request->headers = $data['headers'] ?? [];
        $request->delay = $data['delay'] ?? 0;
        $request->error = $data['error'];
        $request->persistent = $data['persistent'] ?? false;
        return $request;
    }
}

/**
 * Represents a recorded HTTP request for assertion purposes.
 */
class RecordedRequest
{
    public string $method;
    public string $url;
    public array $options;
    public float $timestamp;

    public function __construct(string $method, string $url, array $options, float $timestamp)
    {
        $this->method = $method;
        $this->url = $url;
        $this->options = $options;
        $this->timestamp = $timestamp;
    }
}
