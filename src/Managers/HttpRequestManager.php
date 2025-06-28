<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\Http\CurlMultiHandler;
use Rcalicdan\FiberAsync\Handlers\Http\HttpRequestHandler;
use Rcalicdan\FiberAsync\Handlers\Http\HttpResponseHandler;
use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

class HttpRequestManager
{
    /** @var HttpRequest[] */
    private array $pendingRequests = [];
    /** @var HttpRequest[] */
    private array $activeRequests = [];
    /** @var array<string, HttpRequest> Map of request IDs to requests for cancellation */
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

    /**
     * Add an HTTP request and return a unique request ID for cancellation
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        $requestId = uniqid('http_', true);
        $request = $this->requestHandler->createRequest($url, $options, $callback);
        $request->setId($requestId); // You'll need to add this method to HttpRequest
        
        $this->pendingRequests[] = $request;
        $this->requestsById[$requestId] = $request;
        
        return $requestId;
    }

    /**
     * Cancel an HTTP request by its ID
     */
    public function cancelHttpRequest(string $requestId): bool
    {
        if (!isset($this->requestsById[$requestId])) {
            return false; 
        }

        $request = $this->requestsById[$requestId];
    
        $pendingKey = array_search($request, $this->pendingRequests, true);
        if ($pendingKey !== false) {
            unset($this->pendingRequests[$pendingKey]);
            $this->pendingRequests = array_values($this->pendingRequests); // Re-index
        }
        
        $handle = $request->getHandle();
        if ($handle && isset($this->activeRequests[(int) $handle])) {
            curl_multi_remove_handle($this->multiHandle, $handle);
            curl_close($handle);
            unset($this->activeRequests[(int) $handle]);
        }
        
        unset($this->requestsById[$requestId]);
        
        $callback = $request->getCallback();
        if ($callback) {
            $callback('Request cancelled', null, 0);
        }
        
        return true;
    }

    public function processRequests(): bool
    {
        $workDone = false;

        if ($this->addPendingRequests()) {
            $workDone = true;
        }

        if ($this->processActiveRequests()) {
            $workDone = true;
        }

        return $workDone;
    }

    private function addPendingRequests(): bool
    {
        $added = false;
        while (! empty($this->pendingRequests)) {
            $request = array_shift($this->pendingRequests);
            if ($this->requestHandler->addRequestToMultiHandle($this->multiHandle, $request)) {
                $this->activeRequests[(int) $request->getHandle()] = $request;
                $added = true;
            }
        }

        return $added;
    }

    private function processActiveRequests(): bool
    {
        if (empty($this->activeRequests)) {
            return false;
        }

        $this->curlHandler->executeMultiHandle($this->multiHandle);

        $completed = $this->responseHandler->processCompletedRequests($this->multiHandle, $this->activeRequests);
        
        // Clean up completed requests from the ID map
        foreach ($this->activeRequests as $handle => $request) {
            if (!$request->getHandle()) { // Request completed
                $requestId = $request->getId();
                if ($requestId && isset($this->requestsById[$requestId])) {
                    unset($this->requestsById[$requestId]);
                }
            }
        }
        
        return $completed;
    }

    public function hasRequests(): bool
    {
        return ! empty($this->pendingRequests) || ! empty($this->activeRequests);
    }

    public function __destruct()
    {
        $this->curlHandler->closeMultiHandle($this->multiHandle);
    }
}