<?php

namespace Rcalicdan\FiberAsync\Handlers\Http;

use Exception;
use Rcalicdan\FiberAsync\AsyncEventLoop;
use Rcalicdan\FiberAsync\CancellablePromise;
use Rcalicdan\FiberAsync\Contracts\PromiseInterface;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Http\StreamingResponse;

final readonly class StreamingHandler
{
    public function streamRequest(string $url, array $options, ?callable $onChunk = null): PromiseInterface
    {
        $promise = new CancellablePromise;
        $requestId = null;

        $responseStream = fopen('php://temp', 'w+b');
        if (! $responseStream) {
            $promise->reject(new Exception('Failed to create response stream'));

            return $promise;
        }

        // --- FIX 1: Add a variable to hold the headers ---
        $headerAccumulator = [];

        $streamingOptions = $options + [
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($responseStream, $onChunk) {
                fwrite($responseStream, $data);

                if ($onChunk) {
                    $onChunk($data);
                }

                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headerAccumulator) {
                // This function captures each header line
                $trimmedHeader = trim($header);
                if ($trimmedHeader !== '') {
                    $headerAccumulator[] = $trimmedHeader;
                }

                return strlen($header);
            },
        ];

        $requestId = AsyncEventLoop::getInstance()->addHttpRequest(
            $url,
            $streamingOptions,
            function ($error, $response, $httpCode, $headers = []) use ($promise, $responseStream, &$headerAccumulator) { // Pass accumulator by reference
                if ($promise->isCancelled()) {
                    fclose($responseStream);

                    return;
                }

                if ($error) {
                    fclose($responseStream);
                    $promise->reject(new Exception("Streaming request failed: {$error}"));
                } else {
                    rewind($responseStream);
                    $stream = new Stream($responseStream);
                    // --- FIX 2: Use the collected headers, not the empty ones ---
                    $promise->resolve(new StreamingResponse($stream, $httpCode, $headerAccumulator));
                }
            }
        );

        $promise->setCancelHandler(function () use (&$requestId, $responseStream) {
            if ($requestId !== null) {
                AsyncEventLoop::getInstance()->cancelHttpRequest($requestId);
            }
            if (is_resource($responseStream)) {
                fclose($responseStream);
            }
        });

        return $promise;
    }

    public function downloadFile(string $url, string $destination, array $options = []): PromiseInterface
    {
        $promise = new CancellablePromise;
        $requestId = null;

        $file = fopen($destination, 'wb');
        if (! $file) {
            $promise->reject(new Exception("Cannot open file for writing: {$destination}"));

            return $promise;
        }

        $downloadOptions = $options + [
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($file) {
                return fwrite($file, $data);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($promise) {
                if ($promise->isCancelled()) {
                    return 1;
                }

                return 0;
            },
        ];

        $requestId = AsyncEventLoop::getInstance()->addHttpRequest(
            $url,
            $downloadOptions,
            function ($error, $response, $httpCode, $headers = []) use ($promise, $file, $destination) {
                fclose($file);

                if ($promise->isCancelled()) {
                    unlink($destination);

                    return;
                }

                if ($error) {
                    unlink($destination);
                    $promise->reject(new Exception("Download failed: {$error}"));
                } else {
                    $promise->resolve([
                        'file' => $destination,
                        'status' => $httpCode,
                        'headers' => $headers,
                    ]);
                }
            }
        );

        $promise->setCancelHandler(function () use (&$requestId, $file, $destination) {
            if ($requestId !== null) {
                AsyncEventLoop::getInstance()->cancelHttpRequest($requestId);
            }
            if (is_resource($file)) {
                fclose($file);
            }
            if (file_exists($destination)) {
                unlink($destination);
            }
        });

        return $promise;
    }
}
