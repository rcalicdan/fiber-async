<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\Http\CurlMultiHandler;
use Rcalicdan\FiberAsync\Handlers\Http\HttpRequestHandler;
use Rcalicdan\FiberAsync\Handlers\Http\HttpResponseHandler;
use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

class HttpRequestManager
{
    private array $pendingRequests = [];
    private array $activeRequests = [];
    private array $requestsById = [];
    private \CurlMultiHandle $multiHandle;
    private HttpRequestHandler $requestHandler;
    private HttpResponseHandler $responseHandler;
    private CurlMultiHandler $curlHandler;

    public function __construct()
    {
        $this->requestHandler = new HttpRequestHandler;
        $this->responseHandler = new HttpResponseHandler;
        $this->curlHandler = new CurlMultiHandler;
        $this->multiHandle = $this->curlHandler->createMultiHandle();
    }

    public function getSelectTimeout(): ?float
    {
        if (!$this->hasRequests()) {
            return null;
        }

        if (function_exists('curl_multi_timeout')) {
            $timeout_ms = -1;
            \curl_multi_timeout($this->multiHandle, $timeout_ms);
            
            if ($timeout_ms > 0) {
                return $timeout_ms / 1000.0;
            }
            
            if ($timeout_ms === 0) {
                return 0.0;
            }
        }
        
        return 0.001;
    }

    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        $requestId = uniqid('http_', true);
        $request = $this->requestHandler->createRequest($url, $options, $callback);
        $request->setId($requestId);

        $this->pendingRequests[] = $request;
        $this->requestsById[$requestId] = $request;

        return $requestId;
    }

    public function cancelHttpRequest(string $requestId): bool
    {
        if (!isset($this->requestsById[$requestId])) {
            return false;
        }

        $request = $this->requestsById[$requestId];
        $pendingKey = array_search($request, $this->pendingRequests, true);

        if ($pendingKey !== false) {
            unset($this->pendingRequests[$pendingKey]);
            $this->pendingRequests = array_values($this->pendingRequests);
        }

        $handle = $request->getHandle();
        if ($handle && isset($this->activeRequests[(int) $handle])) {
            curl_multi_remove_handle($this->multiHandle, $handle);
            curl_close($handle);
            unset($this->activeRequests[(int) $handle]);
        }

        unset($this->requestsById[$requestId]);

        if ($callback = $request->getCallback()) {
            $callback('Request cancelled', null, 0);
        }
        return true;
    }

    public function processRequests(): bool
    {
        $this->addPendingRequests();
        $this->processActiveRequests();
        return $this->hasRequests();
    }

    private function addPendingRequests(): void
    {
        while (!empty($this->pendingRequests)) {
            $request = array_shift($this->pendingRequests);
            if ($this->requestHandler->addRequestToMultiHandle($this->multiHandle, $request)) {
                $this->activeRequests[(int) $request->getHandle()] = $request;
            }
        }
    }

    private function processActiveRequests(): void
    {
        if (empty($this->activeRequests)) {
            return;
        }

        $this->curlHandler->executeMultiHandle($this->multiHandle);
        $this->responseHandler->processCompletedRequests($this->multiHandle, $this->activeRequests);

        foreach ($this->activeRequests as $request) {
            if (!$request->getHandle()) {
                if ($requestId = $request->getId()) {
                    unset($this->requestsById[$requestId]);
                }
            }
        }
    }

    public function hasRequests(): bool
    {
        return !empty($this->pendingRequests) || !empty($this->activeRequests);
    }

    public function __destruct()
    {
        $this->curlHandler->closeMultiHandle($this->multiHandle);
    }
}