<?php

namespace Rcalicdan\FiberAsync\Handlers\Http;

final readonly class CurlMultiHandler
{
    public function executeMultiHandle(\CurlMultiHandle $multiHandle): int
    {
        $running = 0;
        curl_multi_exec($multiHandle, $running);
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