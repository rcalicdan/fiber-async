<?php

namespace Rcalicdan\FiberAsync\EventLoop\ValueObjects;

use Rcalicdan\FiberAsync\EventLoop\Interfaces\AsyncHttpRequestInterface;

/**
 * HTTP request implementation for asynchronous processing.
 *
 * This class encapsulates an HTTP request using cURL and provides functionality
 * for asynchronous execution within an event loop system. It manages the cURL
 * handle, callback execution, and request metadata.
 */
class HttpRequest implements AsyncHttpRequestInterface
{
    /**
     * The cURL handle for this HTTP request.
     */
    private \CurlHandle $handle;

    /**
     * Callback function to execute when the request completes.
     *
     * @var callable(?string, ?string, ?int, array<string, mixed>): void
     */
    private $callback;

    /**
     * The URL being requested.
     */
    private string $url;

    /**
     * Optional unique identifier for this request.
     */
    private ?string $id = null;

    /**
     * Creates a new HTTP request instance.
     *
     * @param  string  $url  The URL to request
     * @param  array<int|string, mixed>  $options  cURL options array
     * @param  callable(?string, ?string, ?int, array<string, mixed>): void  $callback  Callback to execute on completion
     */
    public function __construct(string $url, array $options, callable $callback)
    {
        $this->url = $url;
        $this->callback = $callback;
        $this->handle = $this->createCurlHandle($options);
    }

    /**
     * Creates and configures a cURL handle with the provided options.
     *
     * @param  array<int|string, mixed>  $options  cURL options to apply
     * @return \CurlHandle The configured cURL handle
     *
     * @throws \RuntimeException If cURL initialization fails
     */
    private function createCurlHandle(array $options): \CurlHandle
    {
        $handle = curl_init();
        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * Gets the cURL handle for this request.
     *
     * @return \CurlHandle The cURL handle
     */
    public function getHandle(): \CurlHandle
    {
        return $this->handle;
    }

    /**
     * Gets the callback function for this request.
     *
     * @return callable(?string, ?string, ?int, array<string, mixed>): void The callback function
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Gets the URL being requested.
     *
     * @return string The request URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Executes the callback function with the request results.
     *
     * @param  string|null  $error  Error message if the request failed, null on success
     * @param  string|null  $response  Response body from the server
     * @param  int|null  $httpCode  HTTP status code from the response
     * @param  array<string, mixed>  $headers  Response headers as key-value pairs
     *
     * @throws \Throwable Any exception thrown by the callback is propagated
     */
    public function executeCallback(?string $error, ?string $response, ?int $httpCode, array $headers = []): void
    {
        ($this->callback)($error, $response, $httpCode, $headers);
    }

    /**
     * Sets the unique identifier for this request.
     *
     * @param  string  $id  The unique identifier
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * Gets the unique identifier for this request.
     *
     * @return string|null The unique identifier, or null if not set
     */
    public function getId(): ?string
    {
        return $this->id;
    }
}
