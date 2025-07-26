<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Http\Uri;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use RuntimeException;

final readonly class HttpHandler
{
    private StreamingHandler $streamingHandler;

    public function __construct()
    {
        $this->streamingHandler = new StreamingHandler;
    }

    public function request(): Request
    {
        return new Request($this);
    }

    public function get(string $url, array $query = []): PromiseInterface
    {
        return $this->request()->get($url, $query);
    }

    public function post(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->post($url, $data);
    }

    public function put(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->put($url, $data);
    }

    public function delete(string $url): PromiseInterface
    {
        return $this->request()->delete($url);
    }

    public function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    public function download(string $url, string $destination, array $options = []): PromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        return $this->streamingHandler->downloadFile($url, $destination, $curlOptions);
    }

    public function createStream(string $content = ''): Stream
    {
        $resource = fopen('php://temp', 'w+b');
        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }
        return new Stream($resource);
    }

    public function createStreamFromFile(string $path, string $mode = 'rb'): Stream
    {
        $resource = fopen($path, $mode);
        if (!$resource) {
            throw new RuntimeException("Cannot open file: {$path}");
        }
        return new Stream($resource, $path);
    }

    public function fetch(string $url, array $options = []): PromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $promise = new CancellablePromise;
        $requestId = null;
        $requestId = EventLoop::getInstance()->addHttpRequest($url, $curlOptions, function ($error, $response, $httpCode, $headers = []) use ($promise) {
            if ($promise->isCancelled()) {
                return;
            }

            if ($error) {
                $promise->reject(new HttpException("HTTP Request failed: {$error}"));
            } else {
                $promise->resolve(new Response($response, $httpCode, $headers));
            }
        });
        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });
        return $promise;
    }

    public function fetchWithRetry(string $url, array $options, RetryConfig $retryConfig): PromiseInterface
    {
        $promise = new CancellablePromise;
        $attempt = 0;
        $requestId = null;
        $executeRequest = function () use ($url, $options, $retryConfig, $promise, &$attempt, &$requestId, &$executeRequest) {
            $attempt++;
            $requestId = EventLoop::getInstance()->addHttpRequest(
                $url,
                $options,
                function ($error, $response, $httpCode, $headers = []) use ($retryConfig, $promise, $attempt, &$executeRequest) {
                    if ($promise->isCancelled()) {
                        return;
                    }
                    $shouldRetry = $retryConfig->shouldRetry($attempt, $httpCode, $error);
                    if ($error && $shouldRetry) {
                        $delay = $retryConfig->getDelay($attempt);
                        EventLoop::getInstance()->addTimer($delay, function () use ($executeRequest) {
                            $executeRequest();
                        });
                        return;
                    }
                    if ($httpCode > 0 && $shouldRetry) {
                        $delay = $retryConfig->getDelay($attempt);
                        EventLoop::getInstance()->addTimer($delay, function () use ($executeRequest) {
                            $executeRequest();
                        });
                        return;
                    }
                    if ($error) {
                        $promise->reject(new HttpException("HTTP Request failed after {$attempt} attempts: {$error}"));
                    } else {
                        $promise->resolve(new Response($response, $httpCode, $headers));
                    }
                }
            );
        };
        $executeRequest();
        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });
        return $promise;
    }

    private function normalizeFetchOptions(string $url, array $options): array
    {
        if ($this->isCurlOptionsFormat($options)) {
            return $options;
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        if (isset($options['method'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($options['method']);
        }

        if (isset($options['headers'])) {
            $headerStrings = [];
            foreach ($options['headers'] as $name => $value) {
                $headerStrings[] = "{$name}: {$value}";
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headerStrings;
        }

        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = $options['timeout'];
        }

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

    private function isCurlOptionsFormat(array $options): bool
    {
        foreach (array_keys($options) as $key) {
            if (is_int($key) && $key > 0) {
                return true;
            }
        }
        return false;
    }
}
