<?php

namespace Rcalicdan\FiberAsync\Services;

use Rcalicdan\FiberAsync\ValueObjects\HttpRequest;

class HttpRequestManager
{
    /** @var HttpRequest[] */
    private array $pendingRequests = [];
    /** @var HttpRequest[] */
    private array $activeRequests = [];
    private \CurlMultiHandle $multiHandle;
    private array $curlSockets = [];

    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
        
        curl_multi_setopt($this->multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
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

    public function collectStreams(array &$read, array &$write, array &$except): void
    {
        if (empty($this->activeRequests)) {
            return;
        }

        // For curl multi, we need to use curl_multi_select approach
        // This is a simplified version - curl handles its own socket management
    }

    public function handleReadyStreams(array $read, array $write): bool
    {
        return $this->processActiveRequests();
    }

    private function addPendingRequests(): bool
    {
        if (empty($this->pendingRequests)) {
            return false;
        }

        $added = false;
        while (!empty($this->pendingRequests)) {
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
        
        // Non-blocking execution
        $result = curl_multi_exec($this->multiHandle, $running);
        
        if ($result === CURLM_CALL_MULTI_PERFORM) {
            // Need to call again immediately
            return true;
        }

        // Check for completed requests
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
                    $request->executeCallback($error ?: 'Unknown curl error', null, null);
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
        return !empty($this->pendingRequests) || !empty($this->activeRequests);
    }

    public function __destruct()
    {
        // Clean up remaining handles
        foreach ($this->activeRequests as $request) {
            curl_multi_remove_handle($this->multiHandle, $request->getHandle());
            curl_close($request->getHandle());
        }
        
        curl_multi_close($this->multiHandle);
    }
}