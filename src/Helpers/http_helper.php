<?php

use Rcalicdan\FiberAsync\Handlers\FetchWithRetry\RetryHelperHandler;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

if (! function_exists('http')) {
    /**
     * Get HTTP request builder instance
     */
    function http(): Request
    {
        return AsyncHttp::request();
    }
}

if (! function_exists('http_get')) {
    /**
     * Perform async GET request
     */
    function http_get(string $url, array $query = []): PromiseInterface
    {
        return AsyncHttp::get($url, $query);
    }
}

if (! function_exists('http_post')) {
    /**
     * Perform async POST request
     */
    function http_post(string $url, array $data = []): PromiseInterface
    {
        return AsyncHttp::post($url, $data);
    }
}

if (! function_exists('http_stream')) {
    /**
     * Stream an HTTP request
     */
    function http_stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        return AsyncHttp::stream($url, $options, $onChunk);
    }
}

if (! function_exists('http_download')) {
    /**
     * Download a file
     */
    function http_download(string $url, string $destination, array $options = []): PromiseInterface
    {
        return AsyncHttp::download($url, $destination, $options);
    }
}

if (! function_exists('http_put')) {
    /**
     * Perform async PUT request
     */
    function http_put(string $url, array $data = []): PromiseInterface
    {
        return AsyncHttp::put($url, $data);
    }
}

if (! function_exists('http_delete')) {
    /**
     * Perform async DELETE request
     */
    function http_delete(string $url): PromiseInterface
    {
        return AsyncHttp::delete($url);
    }
}

if (! function_exists('fetch')) {
    /**
     * Fetch data from URL (JavaScript-like fetch API)
     */
    function fetch(string $url, array $options = []): PromiseInterface
    {
        return AsyncHttp::fetch($url, $options);
    }
}

if (! function_exists('fetch_with_retry')) {
    /**
     * Fetch data with retry logic
     */
    function fetch_with_retry(string $url, array $options = [], int $maxRetries = 3, float $baseDelay = 1.0): PromiseInterface
    {
        $request = AsyncHttp::request()->retry($maxRetries, $baseDelay);
        $response = RetryHelperHandler::getRetryLogic($request, $url, $options);

        return $response;
    }
}
