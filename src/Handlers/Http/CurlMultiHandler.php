<?php

namespace Rcalicdan\FiberAsync\Handlers\Http;

final readonly class CurlMultiHandler
{
    public function executeMultiHandle(\CurlMultiHandle $multiHandle): int
    {
        $running = null;
        
        do {
            $mrc = curl_multi_exec($multiHandle, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        return $running;
    }

    public function createMultiHandle(): \CurlMultiHandle
    {
        return curl_multi_init();
    }

    public function closeMultiHandle(\CurlMultiHandle $multiHandle): void
    {
        curl_multi_close($multiHandle);
    }
}