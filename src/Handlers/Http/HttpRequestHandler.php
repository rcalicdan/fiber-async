<?php

namespace Rcalicdan\FiberAsync\Handlers\Http;

use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

class HttpRequestHandler
{
    public function createRequest(string $url, array $options, callable $callback): HttpRequest
    {
        return new HttpRequest($url, $options, $callback);
    }

    public function addRequestToMultiHandle(\CurlMultiHandle $multiHandle, HttpRequest $request): bool
    {
        $result = curl_multi_add_handle($multiHandle, $request->getHandle());
        return $result === CURLM_OK;
    }

    public function removeRequestFromMultiHandle(\CurlMultiHandle $multiHandle, HttpRequest $request): bool
    {
        $result = curl_multi_remove_handle($multiHandle, $request->getHandle());
        curl_close($request->getHandle());
        return $result === CURLM_OK;
    }
}