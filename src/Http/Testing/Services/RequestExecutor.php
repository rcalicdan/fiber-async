<?php

namespace Rcalicdan\FiberAsync\Http\Testing\Services;

use Rcalicdan\FiberAsync\Http\CacheConfig;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\RetryConfig;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Http\Testing\Exceptions\MockAssertionException;
use Rcalicdan\FiberAsync\Http\Traits\FetchOptionTrait;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;

class RequestExecutor
{
    use FetchOptionTrait;

    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private CookieManager $cookieManager;
    private RequestRecorder $requestRecorder;
    private CacheManager $cacheManager;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        FileManager $fileManager,
        CookieManager $cookieManager,
        RequestRecorder $requestRecorder,
        CacheManager $cacheManager
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
        $this->cookieManager = $cookieManager;
        $this->requestRecorder = $requestRecorder;
        $this->cacheManager = $cacheManager;
    }

    public function executeSendRequest(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?CacheConfig $cacheConfig = null,
        ?RetryConfig $retryConfig = null,
        ?callable $parentSendRequest = null
    ): PromiseInterface {
        if (! isset($curlOptions['_cookie_jar'])) {
            $this->cookieManager->applyCookiesToCurlOptions($curlOptions, $url);
        }

        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';

        // Handle caching for GET requests
        if ($cacheConfig !== null && $method === 'GET') {
            $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);
            if ($cachedResponse !== null) {
                $this->requestRecorder->recordRequest('GET (FROM CACHE)', $url, $curlOptions);

                return Promise::resolved($cachedResponse);
            }
        }

        $promise = $this->executeMockedRequest(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $retryConfig,
            $parentSendRequest
        );

        // Process cookies from response
        if ($promise instanceof PromiseInterface) {
            $promise = $promise->then(function ($response) {
                if ($response instanceof Response) {
                    $this->cookieManager->processSetCookieHeaders($response->getHeaders());
                }

                return $response;
            });
        }

        // Cache successful responses
        if ($cacheConfig !== null) {
            return $promise->then(function ($response) use ($cacheConfig, $url) {
                if ($response instanceof Response && $response->ok()) {
                    $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
                }

                return $response;
            });
        }

        return $promise;
    }

    public function executeFetch(
        string $url,
        array $options,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $parentFetch = null,
        ?callable $createStream = null
    ): PromiseInterface|CancellablePromiseInterface {
        $method = strtoupper($options['method'] ?? 'GET');
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        // Handle caching for GET requests
        if ($cacheConfig !== null && $method === 'GET') {
            $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);
            if ($cachedResponse !== null) {
                $this->requestRecorder->recordRequest('GET (FROM CACHE)', $url, $curlOptions);

                return Promise::resolved($cachedResponse);
            }
        }

        // Handle retry logic
        if ($retryConfig !== null) {
            return $this->executeWithMockRetry($url, $options, $retryConfig, $method, $mockedRequests);
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        // Find matching mock
        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            $mock = $match['mock'];

            // Remove non-persistent mocks
            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            // Handle download requests
            if (isset($options['download'])) {
                return $this->responseFactory->createMockedDownload(
                    $mock,
                    $options['download'],
                    $this->fileManager
                );
            }

            // Handle streaming requests
            if (isset($options['stream']) && $options['stream'] === true) {
                $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;

                return $this->responseFactory->createMockedStream($mock, $onChunk, $createStream);
            }

            $responsePromise = $this->responseFactory->createMockedResponse($mock);

            // Cache successful GET responses
            if ($cacheConfig !== null && $method === 'GET') {
                return $responsePromise->then(function ($response) use ($cacheConfig, $url) {
                    if ($response instanceof Response && $response->ok()) {
                        $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
                    }

                    return $response;
                });
            }

            return $responsePromise;
        }

        // Handle no match found
        if ($globalSettings['strict_matching'] && ! $globalSettings['allow_passthrough']) {
            throw new MockAssertionException("No mock found for: {$method} {$url}");
        }

        return $parentFetch ? $parentFetch($url, $options) : Promise::rejected(new \RuntimeException('No parent fetch available'));
    }

    private function executeMockedRequest(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?RetryConfig $retryConfig,
        ?callable $parentSendRequest
    ): PromiseInterface {
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

        if ($retryConfig !== null && $match !== null) {
            return $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method, $mockedRequests);
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        if ($match !== null) {
            if (! $match['mock']->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedResponse($match['mock']);
        }

        if ($globalSettings['strict_matching'] && ! $globalSettings['allow_passthrough']) {
            throw new MockAssertionException("No mock found for: {$method} {$url}");
        }

        return $parentSendRequest ? $parentSendRequest($url, $curlOptions, null, $retryConfig) : Promise::rejected(new \RuntimeException('No parent send request available'));
    }

    private function executeWithMockRetry(
        string $url,
        array $options,
        RetryConfig $retryConfig,
        string $method,
        array &$mockedRequests
    ): PromiseInterface {
        $finalPromise = new CancellablePromise;
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        $retryPromise = $this->responseFactory->createRetryableMockedResponse(
            $retryConfig,
            function (int $attemptNumber) use ($method, $url, $curlOptions, &$mockedRequests) {
                $this->requestRecorder->recordRequest($method, $url, $curlOptions);
                $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);
                if ($match === null) {
                    throw new MockAssertionException("No mock for attempt #{$attemptNumber}: {$method} {$url}");
                }
                if (! $match['mock']->isPersistent()) {
                    array_splice($mockedRequests, $match['index'], 1);
                }

                return $match['mock'];
            }
        );

        $retryPromise->then(
            function ($successfulResponse) use ($options, $finalPromise) {
                if (isset($options['download'])) {
                    $destPath = $options['download'] ?? $this->fileManager->createTempFile();
                    file_put_contents($destPath, $successfulResponse->body());
                    $result = [
                        'file' => $destPath,
                        'status' => $successfulResponse->status(),
                        'headers' => $successfulResponse->headers(),
                        'size' => strlen($successfulResponse->body()),
                    ];
                    $finalPromise->resolve($result);
                } elseif (isset($options['stream']) && $options['stream'] === true) {
                    $onChunk = $options['on_chunk'] ?? null;
                    $body = $successfulResponse->body();
                    if ($onChunk) {
                        $onChunk($body);
                    }
                    $finalPromise->resolve(new StreamingResponse(
                        (new HttpHandler)->createStream($body),
                        $successfulResponse->status(),
                        $successfulResponse->headers()
                    ));
                } else {
                    $finalPromise->resolve($successfulResponse);
                }
            },
            fn ($reason) => $finalPromise->reject($reason)
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn () => $retryPromise->cancel());
        }

        return $finalPromise;
    }
}
