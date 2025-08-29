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
 */
final readonly class StreamingHandler
{
    /**
     * Creates a streaming HTTP request with optional real-time chunk processing.
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

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $streamingOptions = array_replace($curlOnlyOptions, [
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
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($promise, $responseStream, &$headerAccumulator): void {
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

                    $streamingResponse = new StreamingResponse($stream, $httpCode ?? 200, $formattedHeaders);

                    if ($httpVersion !== null) {
                        $streamingResponse->setHttpVersion($httpVersion);
                    }

                    $promise->resolve($streamingResponse);
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

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $downloadOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($file): int|false {
                return fwrite($file, $data);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($promise): int {
                if ($promise->isCancelled()) {
                    return 1; // Abort download
                }

                return 0;
            },
        ]);

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $downloadOptions, // Pass the sanitized options
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($promise, $file, $destination): void {
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
                    $fileSize = file_exists($destination) ? filesize($destination) : 0;
                    $promise->resolve([
                        'file' => $destination,
                        'status' => $httpCode ?? 0,
                        'headers' => $headers,
                        'protocol_version' => $httpVersion,
                        'size' => $fileSize,
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
