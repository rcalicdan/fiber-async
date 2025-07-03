<?php

use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Facades\Http;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\RetryConfig;

if (!function_exists('http')) {
    /**
     * Get HTTP request builder instance
     */
    function http(): Request
    {
        return Http::request();
    }
}

if (!function_exists('http_get')) {
    /**
     * Perform async GET request
     */
    function http_get(string $url, array $query = []): PromiseInterface
    {
        return Http::get($url, $query);
    }
}

if (!function_exists('http_post')) {
    /**
     * Perform async POST request
     */
    function http_post(string $url, array $data = []): PromiseInterface
    {
        return Http::post($url, $data);
    }
}

if (!function_exists('http_put')) {
    /**
     * Perform async PUT request
     */
    function http_put(string $url, array $data = []): PromiseInterface
    {
        return Http::put($url, $data);
    }
}

if (!function_exists('http_delete')) {
    /**
     * Perform async DELETE request
     */
    function http_delete(string $url): PromiseInterface
    {
        return Http::delete($url);
    }
}

if (!function_exists('fetch')) {
    /**
     * Fetch data from URL (JavaScript-like fetch API)
     */
    function fetch(string $url, array $options = []): PromiseInterface
    {
        return Http::fetch($url, $options);
    }
}

if (!function_exists('fetch_with_retry')) {
    /**
     * Fetch data with retry logic
     */
    function fetch_with_retry(string $url, array $options = [], int $maxRetries = 3, float $baseDelay = 1.0): PromiseInterface
    {
        $request = Http::request()->retry($maxRetries, $baseDelay);

        if (isset($options['headers'])) {
            $request->headers($options['headers']);
        }

        if (isset($options['method'])) {
            $method = strtoupper($options['method']);
        } else {
            $method = 'GET';
        }

        if (isset($options['body'])) {
            $request->body($options['body']);
        }

        if (isset($options['json'])) {
            $request->json($options['json']);
        }

        if (isset($options['form'])) {
            $request->form($options['form']);
        }

        if (isset($options['timeout'])) {
            $request->timeout($options['timeout']);
        }

        if (isset($options['user_agent'])) {
            $request->userAgent($options['user_agent']);
        }

        if (isset($options['verify_ssl'])) {
            $request->verifySSL($options['verify_ssl']);
        }

        if (isset($options['auth'])) {
            if (isset($options['auth']['bearer'])) {
                $request->bearerToken($options['auth']['bearer']);
            } elseif (isset($options['auth']['basic'])) {
                $request->basicAuth($options['auth']['basic']['username'], $options['auth']['basic']['password']);
            }
        }

        return $request->send($method, $url);
    }
}
