<?php

namespace Rcalicdan\FiberAsync\EventLoop\Managers;

use CurlMultiHandle;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Http\CurlMultiHandler;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Http\HttpRequestHandler;
use Rcalicdan\FiberAsync\EventLoop\IOHandlers\Http\HttpResponseHandler;
use Rcalicdan\FiberAsync\EventLoop\ValueObjects\HttpRequest;

/**
 * Manages the entire lifecycle of HTTP requests for the event loop.
 *
 * This class handles queuing new requests, adding them to a cURL multi-handle for
 * concurrent processing, and processing their responses or cancellations.
 */
class HttpRequestManager
{
    /** @var list<HttpRequest> A queue of requests waiting to be added to the multi-handle. */
    private array $pendingRequests = [];

    /** @var array<int, HttpRequest> An associative array of active requests, keyed by their cURL handle ID. */
    private array $activeRequests = [];

    /** @var array<string, HttpRequest> A map of requests by their unique object hash for cancellation. */
    private array $requestsById = [];

    private readonly CurlMultiHandle $multiHandle;
    private readonly HttpRequestHandler $requestHandler;
    private readonly HttpResponseHandler $responseHandler;
    private readonly CurlMultiHandler $curlHandler;

    public function __construct()
    {
        $this->requestHandler = new HttpRequestHandler;
        $this->responseHandler = new HttpResponseHandler;
        $this->curlHandler = new CurlMultiHandler;
        $this->multiHandle = $this->curlHandler->createMultiHandle();
    }

    /**
     * Adds an HTTP request to the processing queue.
     *
     * @param  string  $url  The URL for the request.
     * @param  array<int, mixed>  $options  cURL options for the request.
     * @param  callable(string|null, string|null, int|null, array<string, mixed>, string|null): void  $callback  The callback to execute upon completion.
     * @return string A unique ID for the request, which can be used for cancellation.
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        $request = $this->requestHandler->createRequest($url, $options, $callback);
        $requestId = spl_object_hash($request);

        $this->pendingRequests[] = $request;
        $this->requestsById[$requestId] = $request;

        return $requestId;
    }

    /**
     * Cancels a pending or active HTTP request by its ID.
     *
     * @param  string  $requestId  The unique ID of the request to cancel.
     * @return bool True if the request was found and canceled, false otherwise.
     */
    public function cancelHttpRequest(string $requestId): bool
    {
        if (! isset($this->requestsById[$requestId])) {
            return false;
        }

        $request = $this->requestsById[$requestId];

        $this->pendingRequests = array_values(
            array_filter(
                $this->pendingRequests,
                static fn(HttpRequest $r): bool => spl_object_hash($r) !== $requestId
            )
        );

        $handle = $request->getHandle();
        $handleId = (int) $handle;
        if (isset($this->activeRequests[$handleId])) {
            curl_multi_remove_handle($this->multiHandle, $handle);
            curl_close($handle);
            unset($this->activeRequests[$handleId]);
        }

        unset($this->requestsById[$requestId]);

        $request->getCallback()('Request cancelled', null, 0, []);

        return true;
    }

    /**
     * Processes all pending and active HTTP requests for one event loop tick.
     *
     * @return bool True if any work was done (request added or processed), false otherwise.
     */
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

    /**
     * Moves all pending requests into the active cURL multi-handle.
     *
     * @return bool True if at least one request was successfully added.
     */
    private function addPendingRequests(): bool
    {
        if (count($this->pendingRequests) === 0) {
            return false;
        }

        while (($request = array_shift($this->pendingRequests)) !== null) {
            if ($this->requestHandler->addRequestToMultiHandle($this->multiHandle, $request)) {
                $this->activeRequests[(int) $request->getHandle()] = $request;
            }
        }

        return true;
    }

    /**
     * Executes the cURL multi-handle and processes any completed requests.
     *
     * @return bool True if at least one request completed during this tick.
     */
    private function processActiveRequests(): bool
    {
        if (count($this->activeRequests) === 0) {
            return false;
        }

        $requestsBefore = $this->activeRequests;

        $this->curlHandler->executeMultiHandle($this->multiHandle);
        $this->responseHandler->processCompletedRequests($this->multiHandle, $this->activeRequests);
        $completedRequests = array_diff_key($requestsBefore, $this->activeRequests);

        // Clean up the completed requests from the master ID map.
        foreach ($completedRequests as $request) {
            $requestId = spl_object_hash($request);
            unset($this->requestsById[$requestId]);
        }

        return count($completedRequests) > 0;
    }

    /**
     * Checks if there are any pending or active HTTP requests.
     *
     * @return bool True if there are any requests in the system.
     */
    public function hasRequests(): bool
    {
        return count($this->pendingRequests) > 0 || count($this->activeRequests) > 0;
    }

    public function __destruct()
    {
        $this->curlHandler->closeMultiHandle($this->multiHandle);
    }
}
