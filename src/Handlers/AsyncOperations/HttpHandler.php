<?php

namespace Rcalicdan\FiberAsync\Handlers\AsyncOperations;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\CancellablePromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;

final readonly class HttpHandler
{
    /**
     * Create a new HTTP request builder
     */
    public function request(): Request
    {
        return new Request($this);
    }

    /**
     * Quick GET request
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        return $this->request()->get($url, $query);
    }

    /**
     * Quick POST request with JSON data
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->post($url, $data);
    }

    /**
     * Quick PUT request
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->put($url, $data);
    }

    /**
     * Quick DELETE request
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->request()->delete($url);
    }

    /**
     * Enhanced fetch with support for both cURL options and JavaScript-like options
     */
    public function fetch(string $url, array $options = []): PromiseInterface
    {
        // Check if options are JavaScript-like or cURL-like
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        $promise = new CancellablePromise;
        $requestId = null;

        $requestId = AsyncEventLoop::getInstance()->addHttpRequest($url, $curlOptions, function ($error, $response, $httpCode, $headers = []) use ($promise) {
            if ($promise->isCancelled()) {
                return;
            }

            if ($error) {
                $promise->reject(new Exception("HTTP Request failed: {$error}"));
            } else {
                $promise->resolve(new Response($response, $httpCode, $headers));
            }
        });

        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                AsyncEventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    /**
     * Normalize fetch options to support both JavaScript-like and cURL formats
     */
    private function normalizeFetchOptions(string $url, array $options): array
    {
        // If options contain cURL constants, assume it's already in cURL format
        if ($this->isCurlOptionsFormat($options)) {
            return $options;
        }

        // Convert JavaScript-like options to cURL options
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        // Handle method
        if (isset($options['method'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($options['method']);
        }

        // Handle headers
        if (isset($options['headers'])) {
            $headerStrings = [];
            foreach ($options['headers'] as $name => $value) {
                $headerStrings[] = "{$name}: {$value}";
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headerStrings;
        }

        // Handle body
        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        // Handle timeout
        if (isset($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = $options['timeout'];
        }

        // Handle other common options
        if (isset($options['follow_redirects'])) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = (bool) $options['follow_redirects'];
        }

        if (isset($options['verify_ssl'])) {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = (bool) $options['verify_ssl'];
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = $options['verify_ssl'] ? 2 : 0;
        }

        if (isset($options['user_agent'])) {
            $curlOptions[CURLOPT_USERAGENT] = $options['user_agent'];
        }

        return $curlOptions;
    }

    /**
     * Check if options are in cURL format (contain cURL constants)
     */
    private function isCurlOptionsFormat(array $options): bool
    {
        foreach (array_keys($options) as $key) {
            if (is_int($key) && $key > 0) {
                return true; // cURL options are positive integers
            }
        }
        return false;
    }
}
