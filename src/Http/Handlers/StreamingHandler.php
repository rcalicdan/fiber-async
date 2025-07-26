<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpException;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpStreamException;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;

final readonly class StreamingHandler
{
    public function streamRequest(string $url, array $options, ?callable $onChunk = null): PromiseInterface
    {
        $promise = new CancellablePromise;
        $requestId = null;

        $responseStream = fopen('php://temp', 'w+b');
        if (! $responseStream) {
            $promise->reject(new HttpStreamException('Failed to create response stream'));

            return $promise;
        }

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

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $streamingOptions,
            function ($error, $response, $httpCode, $headers = []) use ($promise, $responseStream, &$headerAccumulator) { // Pass accumulator by reference
                if ($promise->isCancelled()) {
                    fclose($responseStream);

                    return;
                }

                if ($error) {
                    fclose($responseStream);
                    $promise->reject(new HttpStreamException("Streaming request failed: {$error}"));
                } else {
                    rewind($responseStream);
                    $stream = new Stream($responseStream);
                    $promise->resolve(new StreamingResponse($stream, $httpCode, $headerAccumulator));
                }
            }
        );

        $promise->setCancelHandler(function () use (&$requestId, $responseStream) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
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
            $promise->reject(new HttpStreamException("Cannot open file for writing: {$destination}"));

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

        $requestId = EventLoop::getInstance()->addHttpRequest(
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
                EventLoop::getInstance()->cancelHttpRequest($requestId);
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
