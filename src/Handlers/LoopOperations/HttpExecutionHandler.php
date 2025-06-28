<?php

namespace Rcalicdan\FiberAsync\Handlers\LoopOperations;

use Rcalicdan\FiberAsync\AsyncOperations;

/**
 * Handles HTTP request execution within the async event loop.
 *
 * This class provides a simplified interface for making HTTP requests
 * using the async event loop infrastructure.
 */
final readonly class HttpExecutionHandler
{
    /**
     * Async operations instance for HTTP requests.
     */
    private AsyncOperations $asyncOps;

    /**
     * Loop execution handler for running operations.
     */
    private LoopExecutionHandler $executionHandler;

    /**
     * Initialize the HTTP execution handler.
     *
     * @param  AsyncOperations  $asyncOps  Async operations instance
     * @param  LoopExecutionHandler  $executionHandler  Loop execution handler
     */
    public function __construct(AsyncOperations $asyncOps, LoopExecutionHandler $executionHandler)
    {
        $this->asyncOps = $asyncOps;
        $this->executionHandler = $executionHandler;
    }

    /**
     * Perform a quick HTTP fetch operation.
     *
     * Makes an HTTP request to the specified URL with optional configuration
     * and returns the response data synchronously.
     *
     * @param  string  $url  The URL to fetch
     * @param  array  $options  Optional cURL options for the request
     * @return array Response data including content and metadata
     */
    public function quickFetch(string $url, array $options = []): array
    {
        return $this->executionHandler->run(function () use ($url, $options) {
            return $this->asyncOps->await($this->asyncOps->fetch($url, $options));
        });
    }
}
