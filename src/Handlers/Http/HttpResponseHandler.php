<?php

namespace Rcalicdan\FiberAsync\Handlers\Http;

use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

final readonly class HttpResponseHandler
{
    public function handleSuccessfulResponse($handle, HttpRequest $request): void
    {
        $fullResponse = curl_multi_getcontent($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        $headerStr = substr($fullResponse, 0, $headerSize);
        $body = substr($fullResponse, $headerSize);

        $headers = array_filter(explode("\r\n", trim($headerStr)));

        $request->executeCallback(null, $body, $httpCode, $headers);
    }

    public function handleErrorResponse($handle, HttpRequest $request): void
    {
        $error = curl_error($handle);
        $request->executeCallback($error, null, null, []);
    }

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
