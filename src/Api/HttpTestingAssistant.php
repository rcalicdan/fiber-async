<?php

namespace Rcalicdan\FiberAsync\Api;

use Rcalicdan\FiberAsync\Http\Interfaces\CookieJarInterface;
use Rcalicdan\FiberAsync\Http\Testing\MockRequestBuilder;
use Rcalicdan\FiberAsync\Http\Testing\Services\CookieManager;
use Rcalicdan\FiberAsync\Http\Testing\TestingHttpHandler;

/**
 * A comprehensive HTTP testing assistant for mocking requests and managing test scenarios.
 *
 * This class provides a clean, expressive API for HTTP testing without polluting
 * the main Http client. It handles request mocking, cookie testing, network simulation,
 * file management, and assertions in a testing environment.
 */
class HttpTestingAssistant
{
    private TestingHttpHandler $testingHandler;
    private static ?self $instance = null;

    public function __construct()
    {
        $this->testingHandler = new TestingHttpHandler();
    }

    /**
     * Get the singleton instance of the testing assistant.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Create a fresh testing assistant instance.
     */
    public static function fresh(): self
    {
        return new self();
    }

    /**
     * Reset the singleton instance and create a new one.
     */
    public static function reset(): self
    {
        if (self::$instance !== null) {
            self::$instance->cleanup();
        }
        self::$instance = new self();
        return self::$instance;
    }

    /**
     * Get the underlying testing handler for advanced usage.
     */
    public function getHandler(): TestingHttpHandler
    {
        return $this->testingHandler;
    }

    /**
     * Activate this testing assistant as the HTTP handler for the Http class.
     */
    public function activate(): self
    {
        Http::setInstance($this->testingHandler);
        return $this;
    }

    /**
     * Deactivate testing mode and restore normal HTTP operations.
     */
    public function deactivate(): self
    {
        Http::reset();
        return $this;
    }

    // ========================================
    // Request Mocking
    // ========================================

    /**
     * Create a mock for any HTTP method.
     */
    public function mock(string $method = '*'): MockRequestBuilder
    {
        return $this->testingHandler->mock($method);
    }

    /**
     * Create a mock for GET requests.
     */
    public function mockGet(): MockRequestBuilder
    {
        return $this->mock('GET');
    }

    /**
     * Create a mock for POST requests.
     */
    public function mockPost(): MockRequestBuilder
    {
        return $this->mock('POST');
    }

    /**
     * Create a mock for PUT requests.
     */
    public function mockPut(): MockRequestBuilder
    {
        return $this->mock('PUT');
    }

    /**
     * Create a mock for DELETE requests.
     */
    public function mockDelete(): MockRequestBuilder
    {
        return $this->mock('DELETE');
    }

    /**
     * Create a mock for PATCH requests.
     */
    public function mockPatch(): MockRequestBuilder
    {
        return $this->mock('PATCH');
    }

    // ========================================
    // Cookie Testing
    // ========================================

    /**
     * Get the cookie testing service.
     */
    public function cookies(): CookieManager
    {
        return $this->testingHandler->cookies();
    }

    /**
     * Enable automatic cookie management for all requests.
     */
    public function withCookies(?CookieJarInterface $jar = null): self
    {
        $this->testingHandler->withGlobalCookieJar($jar);
        return $this;
    }

    /**
     * Create and use a file-based cookie jar for all requests.
     */
    public function withCookieFile(?string $filename = null, bool $includeSessionCookies = true): self
    {
        $this->testingHandler->withGlobalFileCookieJar($filename, $includeSessionCookies);
        return $this;
    }

    /**
     * Add a cookie to the default cookie jar.
     */
    public function addCookie(
        string $name,
        string $value,
        ?string $domain = null,
        ?string $path = '/',
        ?int $expires = null,
        bool $secure = false,
        bool $httpOnly = false,
        ?string $sameSite = null
    ): self {
        $this->testingHandler->cookies()->addCookie(
            $name, $value, $domain, $path, $expires, $secure, $httpOnly, $sameSite
        );
        return $this;
    }

    /**
     * Add multiple cookies at once.
     */
    public function addCookies(array $cookies): self
    {
        $this->testingHandler->cookies()->addCookies($cookies);
        return $this;
    }

    /**
     * Clear all cookies from the default cookie jar.
     */
    public function clearCookies(): self
    {
        $this->testingHandler->cookies()->clearCookies();
        return $this;
    }

    /**
     * Get the number of cookies in the default cookie jar.
     */
    public function getCookieCount(): int
    {
        return $this->testingHandler->cookies()->getCookieCount();
    }

    /**
     * Get debug information about all cookie jars.
     */
    public function getCookieDebugInfo(): array
    {
        return $this->testingHandler->cookies()->getDebugInfo();
    }

    // ========================================
    // Cookie Assertions
    // ========================================

    /**
     * Assert that a cookie exists in the default cookie jar.
     */
    public function assertCookieExists(string $name): self
    {
        $this->testingHandler->assertCookieExists($name);
        return $this;
    }

    /**
     * Assert that a cookie has a specific value.
     */
    public function assertCookieValue(string $name, string $expectedValue): self
    {
        $this->testingHandler->assertCookieValue($name, $expectedValue);
        return $this;
    }

    /**
     * Assert that a cookie was sent in the most recent request.
     */
    public function assertCookieSent(string $name): self
    {
        $this->testingHandler->assertCookieSent($name);
        return $this;
    }

    // ========================================
    // Request Assertions
    // ========================================

    /**
     * Assert that a specific request was made.
     */
    public function assertRequestMade(string $method, string $url, array $options = []): self
    {
        $this->testingHandler->assertRequestMade($method, $url, $options);
        return $this;
    }

    /**
     * Assert that no HTTP requests were made.
     */
    public function assertNoRequestsMade(): self
    {
        $this->testingHandler->assertNoRequestsMade();
        return $this;
    }

    /**
     * Assert a specific number of requests were made.
     */
    public function assertRequestCount(int $expected): self
    {
        $this->testingHandler->assertRequestCount($expected);
        return $this;
    }

    /**
     * Assert that a GET request was made to a specific URL.
     */
    public function assertGetRequestMade(string $url): self
    {
        return $this->assertRequestMade('GET', $url);
    }

    /**
     * Assert that a POST request was made to a specific URL.
     */
    public function assertPostRequestMade(string $url): self
    {
        return $this->assertRequestMade('POST', $url);
    }

    // ========================================
    // Network Simulation
    // ========================================

    /**
     * Enable network simulation for testing unreliable network conditions.
     */
    public function enableNetworkSimulation(array $settings = []): self
    {
        $this->testingHandler->enableNetworkSimulation($settings);
        return $this;
    }

    /**
     * Simulate high network failure rates.
     */
    public function simulateUnstableNetwork(float $failureRate = 0.3, float $timeoutRate = 0.2): self
    {
        return $this->enableNetworkSimulation([
            'failure_rate' => $failureRate,
            'timeout_rate' => $timeoutRate,
            'connection_failure_rate' => 0.1,
            'default_delay' => [0.1, 2.0], // Random delay between 0.1-2.0 seconds
        ]);
    }

    /**
     * Simulate slow network conditions.
     */
    public function simulateSlowNetwork(array $delayRange = [2.0, 10.0]): self
    {
        return $this->enableNetworkSimulation([
            'default_delay' => $delayRange,
            'timeout_rate' => 0.1,
        ]);
    }

    // ========================================
    // Configuration
    // ========================================

    /**
     * Set strict matching mode for mocked requests.
     */
    public function strictMatching(bool $strict = true): self
    {
        $this->testingHandler->setStrictMatching($strict);
        return $this;
    }

    /**
     * Allow requests to pass through to real endpoints when no mock matches.
     */
    public function allowPassthrough(bool $allow = true): self
    {
        $this->testingHandler->setAllowPassthrough($allow);
        return $this;
    }

    /**
     * Enable or disable request recording.
     */
    public function recordRequests(bool $record = true): self
    {
        $this->testingHandler->setRecordRequests($record);
        return $this;
    }

    /**
     * Enable automatic temporary file management.
     */
    public function autoManageFiles(bool $enabled = true): self
    {
        $this->testingHandler->setAutoTempFileManagement($enabled);
        return $this;
    }

    // ========================================
    // File Management
    // ========================================

    /**
     * Create a temporary file for testing.
     */
    public function createTempFile(?string $filename = null, string $content = ''): string
    {
        return $this->testingHandler->createTempFile($filename, $content);
    }

    /**
     * Create a temporary directory for testing.
     */
    public function createTempDirectory(string $prefix = 'http_test_'): string
    {
        return $this->testingHandler->createTempDirectory($prefix);
    }

    /**
     * Get a temporary file path.
     */
    public function getTempPath(?string $filename = null): string
    {
        return TestingHttpHandler::getTempPath($filename);
    }

    // ========================================
    // Inspection & Debugging
    // ========================================

    /**
     * Get the request history for inspection.
     */
    public function getRequestHistory(): array
    {
        return $this->testingHandler->getRequestHistory();
    }

    /**
     * Get the last request made.
     */
    public function getLastRequest(): ?\Rcalicdan\FiberAsync\Http\Testing\RecordedRequest
    {
        $history = $this->getRequestHistory();
        return empty($history) ? null : end($history);
    }

    /**
     * Get detailed information about the current testing state.
     */
    public function getTestingInfo(): array
    {
        return [
            'request_count' => count($this->getRequestHistory()),
            'cookie_count' => $this->getCookieCount(),
            'cookie_debug' => $this->getCookieDebugInfo(),
            'last_request' => $this->getLastRequest(),
        ];
    }

    /**
     * Print debugging information to console.
     */
    public function debug(): self
    {
        $info = $this->getTestingInfo();
        echo "=== HTTP Testing Assistant Debug Info ===\n";
        echo "Requests made: " . $info['request_count'] . "\n";
        echo "Cookies stored: " . $info['cookie_count'] . "\n";
        
        if ($info['last_request']) {
            echo "Last request: " . $info['last_request']->method . " " . $info['last_request']->url . "\n";
        }
        
        if (!empty($info['cookie_debug'])) {
            echo "Cookie details: " . json_encode($info['cookie_debug'], JSON_PRETTY_PRINT) . "\n";
        }
        
        echo "==========================================\n";
        return $this;
    }

    // ========================================
    // Cleanup
    // ========================================

    /**
     * Clean up all testing state and temporary files.
     */
    public function cleanup(): void
    {
        $this->testingHandler->reset();
    }

    /**
     * Reset all mocks and request history but keep configuration.
     */
    public function clearMocks(): self
    {
        $this->testingHandler->reset();
        return $this;
    }
}