<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpStreamException;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Http\StreamingResponse;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Handles non-blocking HTTP streaming operations with cancellation support.
 *
 * Provides asynchronous HTTP request streaming and file downloads using the
 * Event loop. Supports real-time chunk processing and proper
 * resource cleanup on cancellation.
 */
final readonly class StreamingHandler
{
    /**
     * Creates a streaming HTTP request with optional real-time chunk processing.
     *
     * Sends a non-blocking HTTP request and streams the response data as it arrives.
     * The response is buffered in a temporary stream while optionally calling a
     * chunk processor for immediate handling of incoming data.
     *
     * @param  string  $url  The target URL for the HTTP request
     * @param  array<int, mixed>  $options  cURL options (CURLOPT_WRITEFUNCTION and CURLOPT_HEADERFUNCTION will be overridden)
     * @param  callable|null  $onChunk  Optional callback for processing each data chunk: function(string $chunk): void
     * @return CancellablePromiseInterface<StreamingResponse> Promise resolving to StreamingResponse with buffered stream, status, and headers
     *
     * @throws HttpStreamException On stream creation failure (via promise rejection)
     */
    public function streamRequest(string $url, array $options, ?callable $onChunk = null): CancellablePromiseInterface
    {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise;

        $responseStream = fopen('php://temp', 'w+b');
        if ($responseStream === false) {
            $promise->reject(new HttpStreamException('Failed to create response stream'));

            return $promise;
        }

        /** @var list<string> $headerAccumulator */
        $headerAccumulator = [];

        $streamingOptions = array_replace($options, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($responseStream, $onChunk): int {
                fwrite($responseStream, $data);
                if ($onChunk !== null) {
                    $onChunk($data);
                }

                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$headerAccumulator): int {
                $trimmedHeader = trim($header);
                if ($trimmedHeader !== '') {
                    $headerAccumulator[] = $trimmedHeader;
                }

                return strlen($header);
            },
        ]);

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $streamingOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = []) use ($promise, $responseStream, &$headerAccumulator): void {
                if ($promise->isCancelled()) {
                    fclose($responseStream);

                    return;
                }

                if ($error !== null) {
                    fclose($responseStream);
                    $promise->reject(new HttpStreamException("Streaming request failed: {$error}"));
                } else {
                    rewind($responseStream);
                    $stream = new Stream($responseStream);

                    /** @var array<string, string|array<string>> $formattedHeaders */
                    $formattedHeaders = [];
                    foreach ($headerAccumulator as $header) {
                        if (str_contains($header, ':')) {
                            [$key, $value] = explode(':', $header, 2);
                            $key = trim($key);
                            $value = trim($value);
                            if (isset($formattedHeaders[$key])) {
                                if (is_array($formattedHeaders[$key])) {
                                    $formattedHeaders[$key][] = $value;
                                } else {
                                    $formattedHeaders[$key] = [$formattedHeaders[$key], $value];
                                }
                            } else {
                                $formattedHeaders[$key] = $value;
                            }
                        }
                    }

                    $promise->resolve(new StreamingResponse($stream, $httpCode ?? 200, $formattedHeaders));
                }
            }
        );

        $promise->setCancelHandler(function () use ($requestId, $responseStream): void {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
            if (is_resource($responseStream)) {
                fclose($responseStream);
            }
        });

        return $promise;
    }

    /**
     * Downloads a file asynchronously to a specified destination with cancellation support.
     *
     * Performs a non-blocking HTTP download, writing data directly to the destination file
     * as it arrives. Includes progress tracking and automatic cleanup on cancellation or failure.
     * The download can be cancelled at any time, which will abort the request and remove
     * any partially downloaded file.
     *
     * @param  string  $url  The source URL to download from
     * @param  string  $destination  Local file path where the download will be saved
     * @param  array<int, mixed>  $options  Additional cURL options (CURLOPT_WRITEFUNCTION will be overridden)
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}>
     *                                                                                              Promise resolving to array with download info: file path, HTTP status, and response headers
     *
     * @throws HttpStreamException On file creation failure (via promise rejection)
     * @throws Exception On download failure (via promise rejection)
     */
    public function downloadFile(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array{file: string, status: int, headers: array<mixed>}> $promise */
        $promise = new CancellablePromise;

        $file = fopen($destination, 'wb');
        if ($file === false) {
            $promise->reject(new HttpStreamException("Cannot open file for writing: {$destination}"));

            return $promise;
        }

        $downloadOptions = array_replace($options, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($file): int|false {
                return fwrite($file, $data);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($promise): int {
                if ($promise->isCancelled()) {
                    return 1;
                }

                return 0;
            },
        ]);

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $downloadOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = []) use ($promise, $file, $destination): void {
                fclose($file);

                if ($promise->isCancelled()) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    return;
                }

                if ($error !== null) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }
                    $promise->reject(new Exception("Download failed: {$error}"));
                } else {
                    $promise->resolve([
                        'file' => $destination,
                        'status' => $httpCode ?? 0,
                        'headers' => $headers,
                    ]);
                }
            }
        );

        $promise->setCancelHandler(function () use ($requestId, $file, $destination): void {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
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
