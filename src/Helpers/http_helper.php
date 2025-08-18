<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Http\Handlers\RetryHelperHandler;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

if (! function_exists('http')) {
    /**
     * Get HTTP request builder instance.
     *
     * Returns a new HTTP request builder that can be used to configure
     * and execute HTTP requests with method chaining.
     *
     * @return Request HTTP request builder instance
     *
     * @example
     * $response = await(http()->get('https://api.example.com'));
     */
    function http(): Request
    {
        return Http::request();
    }
}

if (! function_exists('http_get')) {
    /**
     * Perform an asynchronous GET request.
     *
     * Sends a GET request to the specified URL with optional query parameters
     * without blocking the event loop.
     *
     * @param  string  $url  The URL to send the request to
     * @param  array<string, mixed>  $query  Optional query parameters
     * @return PromiseInterface<Response> Promise that resolves with the response
     *
     * @example
     * $response = await(http_get('https://api.example.com', ['key' => 'value']));
     */
    function http_get(string $url, array $query = []): PromiseInterface
    {
        return Http::get($url, $query);
    }
}

if (! function_exists('http_post')) {
    /**
     * Perform an asynchronous POST request.
     *
     * Sends a POST request to the specified URL with optional data payload
     * without blocking the event loop.
     *
     * @param  string  $url  The URL to send the request to
     * @param  array<string, mixed>  $data  Optional data payload
     * @return PromiseInterface<Response> Promise that resolves with the response
     *
     * @example
     * $response = await(http_post('https://api.example.com', ['name' => 'John']));
     */
    function http_post(string $url, array $data = []): PromiseInterface
    {
        return Http::post($url, $data);
    }
}

if (! function_exists('http_stream')) {
    /**
     * Stream an HTTP request.
     *
     * Performs an HTTP request and processes the response as a stream,
     * calling the provided callback for each chunk of data received.
     *
     * @param  string  $url  The URL to stream from
     * @param  array<int|string, mixed>  $options  Request options
     * @param  callable|null  $onChunk  Callback to handle each chunk
     * @return CancellablePromiseInterface<StreamingResponse> Promise that resolves when streaming completes
     *
     * @example
     * await(http_stream('https://api.example.com/data', [], function($chunk) {
     *     echo "Received: " . $chunk;
     * }));
     */
    function http_stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        /** @var CancellablePromiseInterface<StreamingResponse> */
        return Http::stream($url, $options, $onChunk);
    }
}

if (! function_exists('http_download')) {
    /**
     * Download a file from a URL.
     *
     * Downloads a file from the specified URL and saves it to the destination
     * path without blocking the event loop.
     *
     * @param  string  $url  The URL to download from
     * @param  string  $destination  The local path to save the file
     * @param  array<int|string, mixed>  $options  Download options
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> Promise that resolves when download completes
     *
     * @example
     * await(http_download('https://example.com/file.zip', '/local/file.zip'));
     */
    function http_download(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        return Http::download($url, $destination, $options);
    }
}

if (! function_exists('http_put')) {
    /**
     * Perform an asynchronous PUT request.
     *
     * Sends a PUT request to the specified URL with optional data payload
     * without blocking the event loop.
     *
     * @param  string  $url  The URL to send the request to
     * @param  array<string, mixed>  $data  Optional data payload
     * @return PromiseInterface<Response> Promise that resolves with the response
     *
     * @example
     * $response = await(http_put('https://api.example.com/resource/1', ['name' => 'Updated']));
     */
    function http_put(string $url, array $data = []): PromiseInterface
    {
        return Http::put($url, $data);
    }
}

if (! function_exists('http_delete')) {
    /**
     * Perform an asynchronous DELETE request.
     *
     * Sends a DELETE request to the specified URL without blocking the event loop.
     *
     * @param  string  $url  The URL to send the request to
     * @return PromiseInterface<Response> Promise that resolves with the response
     *
     * @example
     * $response = await(http_delete('https://api.example.com/resource/1'));
     */
    function http_delete(string $url): PromiseInterface
    {
        return Http::delete($url);
    }
}

if (! function_exists('fetch')) {
    /**
     * Fetch data from URL (JavaScript-like fetch API).
     *
     * Provides a JavaScript-like fetch interface for making HTTP requests
     * with flexible options configuration.
     *
     * @param  string  $url  The URL to fetch from
     * @param  array<int|string, mixed>  $options  Request options (method, headers, body, etc.)
     * @return PromiseInterface<Response> Promise that resolves with the response
     *
     * @example
     * $response = await(fetch('https://api.example.com', [
     *     'method' => 'POST',
     *     'headers' => ['Content-Type' => 'application/json'],
     *     'body' => json_encode(['key' => 'value'])
     * ]));
     */
    function fetch(string $url, array $options = []): PromiseInterface
    {
        return Http::fetch($url, $options);
    }
}

if (! function_exists('fetch_with_retry')) {
    /**
     * Fetch data with automatic retry logic.
     *
     * Performs an HTTP request with automatic retry on failure, using
     * exponential backoff to space out retry attempts.
     *
     * @param  string  $url  The URL to fetch from
     * @param  array{
     *     headers?: array<string, string>,
     *     method?: string,
     *     body?: string,
     *     json?: array<string, mixed>,
     *     form?: array<string, mixed>,
     *     timeout?: int,
     *     user_agent?: string,
     *     verify_ssl?: bool,
     *     auth?: array{
     *         bearer?: string,
     *         basic?: array{username: string, password: string}
     *     }
     * }  $options  Request options
     * @param  int  $maxRetries  Maximum number of retry attempts
     * @param  float  $baseDelay  Base delay between retries in seconds
     * @return PromiseInterface<Response> Promise that resolves with the response
     *
     * @example
     * $response = await(fetch_with_retry('https://api.example.com', [
     *     'method' => 'POST',
     *     'headers' => ['Content-Type' => 'application/json'],
     *     'json' => ['key' => 'value']
     * ], 3, 1.0));
     */
    function fetch_with_retry(string $url, array $options = [], int $maxRetries = 3, float $baseDelay = 1.0): PromiseInterface
    {
        $request = Http::request()->retry($maxRetries, $baseDelay);
        $response = RetryHelperHandler::getRetryLogic($request, $url, $options);

        return $response;
    }
}
