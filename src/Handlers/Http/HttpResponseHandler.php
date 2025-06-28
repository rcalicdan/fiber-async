<?php

namespace Rcalicdan\FiberAsync\Handlers\Http;

use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

/**
 * Handles HTTP response processing and callback execution.
 *
 * This class manages the processing of completed HTTP requests,
 * including successful responses, error handling, and callback execution.
 */
final readonly class HttpResponseHandler
{
    /**
     * Handle a successful HTTP response.
     *
     * Extracts the response content and HTTP status code from a completed
     * request and executes the associated callback with the success data.
     *
     * @param  \CurlHandle  $handle  The completed cURL handle
     * @param  HttpRequest  $request  The request object
     */
    public function handleSuccessfulResponse($handle, HttpRequest $request): void
    {
        $response = curl_multi_getcontent($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $request->executeCallback(null, $response, $httpCode);
    }

    /**
     * Handle an HTTP response with an error.
     *
     * Extracts the error message from a failed request and executes the
     * associated callback with the error data.
     *
     * @param  \CurlHandle  $handle  The failed cURL handle
     * @param  HttpRequest  $request  The request object
     */
    public function handleErrorResponse($handle, HttpRequest $request): void
    {
        $error = curl_error($handle);
        $request->executeCallback($error, null, null);
    }

    /**
     * Process all completed requests in a multi-handle.
     *
     * Checks for completed requests, processes their responses (success or error),
     * executes callbacks, and cleans up resources. Updates the active requests array.
     *
     * @param  \CurlMultiHandle  $multiHandle  The multi-handle to check
     * @param  array  &$activeRequests  Reference to active requests array
     * @return bool True if any requests were processed
     */
    public function processCompletedRequests(\CurlMultiHandle $multiHandle, array &$activeRequests): bool
    {
        $processed = false;

        while ($info = curl_multi_info_read($multiHandle)) {
            $handle = $info['handle'];
            $handleId = (int) $handle;

            if (isset($activeRequests[$handleId])) {
                $request = $activeRequests[$handleId];

                if ($info['result'] === CURLE_OK) {
                    $this->handleSuccessfulResponse($handle, $request);
                } else {
                    $this->handleErrorResponse($handle, $request);
                }

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
                unset($activeRequests[$handleId]);
                $processed = true;
            }
        }

        return $processed;
    }
}
