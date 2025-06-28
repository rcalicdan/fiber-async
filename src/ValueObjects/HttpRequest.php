<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

use Rcalicdan\FiberAsync\Contracts\HttpRequestInterface;

/**
 * Value object representing an asynchronous HTTP request.
 *
 * This class encapsulates all the data and behavior needed for a single HTTP
 * request in the async system. It manages the cURL handle configuration,
 * stores the completion callback, and provides methods for executing the
 * request within the event loop context.
 *
 * The class handles the low-level cURL configuration automatically while
 * providing a clean interface for the event loop to manage request lifecycle
 * and callback execution when requests complete.
 */
class HttpRequest implements HttpRequestInterface
{
    /**
     * @var \CurlHandle The cURL handle configured for this specific request
     */
    private \CurlHandle $handle;

    /**
     * @var callable Callback function to execute when the request completes
     */
    private $callback;

    /**
     * @var string The target URL for this HTTP request
     */
    private string $url;

    /**
     * Create a new HTTP request with specified configuration.
     *
     * Initializes the HTTP request with the target URL, request options,
     * and completion callback. The cURL handle is configured automatically
     * based on the provided options, with sensible defaults for timeout,
     * SSL verification, and other common settings.
     *
     * @param string $url The URL to request
     * @param array $options Request configuration options
     * @param callable $callback Function to call when request completes
     */
    public function __construct(string $url, array $options, callable $callback)
    {
        $this->url = $url;
        $this->callback = $callback;
        $this->handle = $this->createCurlHandle($url, $options);
    }

    /**
     * Create and configure a cURL handle for the HTTP request.
     *
     * Sets up the cURL handle with appropriate options based on the request
     * configuration. Handles common HTTP methods, headers, timeouts, and
     * SSL settings. Provides sensible defaults for options not explicitly
     * specified to ensure reliable request behavior.
     *
     * @param string $url The target URL for the request
     * @param array $options Configuration options for the request
     * @return \CurlHandle Configured cURL handle ready for execution
     */
    private function createCurlHandle(string $url, array $options): \CurlHandle
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 10,
            CURLOPT_USERAGENT => $options['user_agent'] ?? 'PHP-Async-Client/1.0',
            CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'] ?? true,
        ]);

        if (isset($options['method']) && $options['method'] === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            if (isset($options['data'])) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $options['data']);
            }
        }

        if (isset($options['headers'])) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $options['headers']);
        }

        return $handle;
    }

    /**
     * Get the configured cURL handle for this request.
     *
     * Provides access to the underlying cURL handle for the event loop
     * to manage request execution. The handle is pre-configured and
     * ready for use in multi-handle operations.
     *
     * @return \CurlHandle The configured cURL handle
     */
    public function getHandle(): \CurlHandle
    {
        return $this->handle;
    }

    /**
     * Get the completion callback for this request.
     *
     * Returns the callback function that should be executed when the
     * HTTP request completes, either successfully or with an error.
     * The callback handles promise resolution and error handling.
     *
     * @return callable The completion callback function
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Get the target URL for this request.
     *
     * Returns the URL that this request is configured to access.
     * Useful for logging, debugging, and request tracking purposes.
     *
     * @return string The target URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Execute the completion callback with request results.
     *
     * Called by the event loop when the HTTP request completes. Passes
     * the request results (error, response body, HTTP status code) to
     * the completion callback for promise resolution or rejection.
     *
     * @param string|null $error Error message if the request failed
     * @param string|null $response Response body if the request succeeded
     * @param int|null $httpCode HTTP status code from the response
     */
    public function executeCallback(?string $error, ?string $response, ?int $httpCode): void
    {
        ($this->callback)($error, $response, $httpCode);
    }
}