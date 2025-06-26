<?php

// src/Services/HttpRequestManager.php

namespace Rcalicdan\FiberAsync\Managers;

use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

class HttpRequestManager
{
    /** @var HttpRequest[] */
    private array $pendingRequests = [];
    /** @var HttpRequest[] */
    private array $activeRequests = [];
    private \CurlMultiHandle $multiHandle;

    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
    }

    public function addHttpRequest(string $url, array $options, callable $callback): void
    {
        $this->pendingRequests[] = new HttpRequest($url, $options, $callback);
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
            curl_multi_add_handle($this->multiHandle, $request->getHandle());
            $this->activeRequests[(int) $request->getHandle()] = $request;
            $added = true;
        }

        return $added;
    }

    private function processActiveRequests(): bool
    {
        if (empty($this->activeRequests)) {
            return false;
        }

        $processed = false;
        $running = null;

        do {
            $mrc = curl_multi_exec($this->multiHandle, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($this->multiHandle)) {
            $handle = $info['handle'];
            $handleId = (int) $handle;

            if (isset($this->activeRequests[$handleId])) {
                $request = $this->activeRequests[$handleId];

                if ($info['result'] === CURLE_OK) {
                    $response = curl_multi_getcontent($handle);
                    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                    $request->executeCallback(null, $response, $httpCode);
                } else {
                    $error = curl_error($handle);
                    $request->executeCallback($error, null, null);
                }

                curl_multi_remove_handle($this->multiHandle, $handle);
                curl_close($handle);
                unset($this->activeRequests[$handleId]);
                $processed = true;
            }
        }

        return $processed;
    }

    public function hasRequests(): bool
    {
        return ! empty($this->pendingRequests) || ! empty($this->activeRequests);
    }

    public function __destruct()
    {
        curl_multi_close($this->multiHandle);
    }
}
