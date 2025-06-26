<?php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\Handlers\Http\HttpRequestHandler;
use Rcalicdan\FiberAsync\Handlers\Http\HttpResponseHandler;
use Rcalicdan\FiberAsync\Handlers\Http\CurlMultiHandler;
use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

class HttpRequestManager
{
    /** @var HttpRequest[] */
    private array $pendingRequests = [];
    /** @var HttpRequest[] */
    private array $activeRequests = [];
    private \CurlMultiHandle $multiHandle;

    private HttpRequestHandler $requestHandler;
    private HttpResponseHandler $responseHandler;
    private CurlMultiHandler $curlHandler;

    public function __construct()
    {
        $this->requestHandler = new HttpRequestHandler();
        $this->responseHandler = new HttpResponseHandler();
        $this->curlHandler = new CurlMultiHandler();
        $this->multiHandle = $this->curlHandler->createMultiHandle();
    }

    public function addHttpRequest(string $url, array $options, callable $callback): void
    {
        $request = $this->requestHandler->createRequest($url, $options, $callback);
        $this->pendingRequests[] = $request;
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
        while (!empty($this->pendingRequests)) {
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
        return $this->responseHandler->processCompletedRequests($this->multiHandle, $this->activeRequests);
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