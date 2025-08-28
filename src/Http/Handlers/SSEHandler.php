<?php

namespace Rcalicdan\FiberAsync\Http\Handlers;

use Exception;
use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Exceptions\HttpStreamException;
use Rcalicdan\FiberAsync\Http\SSE\SSEConnectionState;
use Rcalicdan\FiberAsync\Http\SSE\SSEEvent;
use Rcalicdan\FiberAsync\Http\SSE\SSEReconnectConfig;
use Rcalicdan\FiberAsync\Http\SSE\SSEResponse;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Promise\CancellablePromise;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Dedicated handler for Server-Sent Events (SSE) connections with reconnection support.
 */
class SSEHandler
{
    private StreamingHandler $streamingHandler;

    public function __construct(?StreamingHandler $streamingHandler = null)
    {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler;
    }

    /**
     * Creates an SSE connection with optional reconnection logic.
     *
     * @param string $url The SSE endpoint URL
     * @param array<int, mixed> $options cURL options
     * @param callable(SSEEvent): void|null $onEvent Optional callback for each SSE event
     * @param callable(string): void|null $onError Optional callback for connection errors
     * @param SSEReconnectConfig|null $reconnectConfig Optional reconnection configuration
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function connect(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        if ($reconnectConfig !== null) {
            return $this->connectWithReconnection($url, $options, $onEvent, $onError, $reconnectConfig);
        }

        return $this->createSSEConnection($url, $options, $onEvent, $onError);
    }

    /**
     * Creates an SSE connection with automatic reconnection logic.
     */
    private function connectWithReconnection(
        string $url,
        array $options,
        ?callable $onEvent,
        ?callable $onError,
        SSEReconnectConfig $reconnectConfig
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $mainPromise */
        $mainPromise = new CancellablePromise;
        
        $connectionState = new SSEConnectionState($url, $options, $reconnectConfig);
        
        // Wrap callbacks to handle reconnection
        $wrappedOnEvent = $this->wrapEventCallback($onEvent, $connectionState);
        $wrappedOnError = $this->wrapErrorCallback($onError, $connectionState, $mainPromise);
        
        // Start initial connection
        $this->attemptConnection($connectionState, $wrappedOnEvent, $wrappedOnError, $mainPromise);
        
        $mainPromise->setCancelHandler(function () use ($connectionState) {
            $connectionState->cancel();
        });
        
        return $mainPromise;
    }

    /**
     * Attempts to establish an SSE connection.
     */
    private function attemptConnection(
        SSEConnectionState $connectionState,
        ?callable $onEvent,
        ?callable $onError,
        CancellablePromise $mainPromise
    ): void {
        if ($connectionState->isCancelled()) {
            return;
        }

        $connectionState->incrementAttempt();
        
        // Add Last-Event-ID header if we have one
        $options = $connectionState->getOptions();
        if ($connectionState->getLastEventId() !== null) {
            $headers = $options[CURLOPT_HTTPHEADER] ?? [];
            $headers[] = 'Last-Event-ID: ' . $connectionState->getLastEventId();
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $connectionPromise = $this->createSSEConnection(
            $connectionState->getUrl(),
            $options,
            $onEvent,
            $onError
        );

        $connectionState->setCurrentConnection($connectionPromise);

        $connectionPromise->then(
            function (SSEResponse $response) use ($mainPromise, $connectionState) {
                if (!$mainPromise->isResolved()) {
                    $mainPromise->resolve($response);
                }
                $connectionState->onConnected();
            },
            function (Exception $error) use ($mainPromise, $connectionState, $onEvent, $onError) {
                if ($connectionState->isCancelled()) {
                    return;
                }

                $shouldReconnect = $connectionState->shouldReconnect($error);
                
                if (!$shouldReconnect) {
                    if (!$mainPromise->isResolved()) {
                        $mainPromise->reject($error);
                    }
                    return;
                }

                // Schedule reconnection
                $delay = $connectionState->getReconnectDelay();
                $connectionState->getConfig()->onReconnect?->call($this, $connectionState->getAttemptCount(), $delay, $error);

                EventLoop::getInstance()->addTimer($delay, function () use ($connectionState, $onEvent, $onError, $mainPromise) {
                    $this->attemptConnection($connectionState, $onEvent, $onError, $mainPromise);
                });
            }
        );
    }

    /**
     * Creates a basic SSE connection without reconnection.
     */
    private function createSSEConnection(
        string $url,
        array $options,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise;

        $responseStream = fopen('php://temp', 'w+b');
        if ($responseStream === false) {
            $promise->reject(new HttpStreamException('Failed to create SSE response stream'));
            return $promise;
        }

        /** @var list<string> $headerAccumulator */
        $headerAccumulator = [];
        $sseResponse = null;

        // Filter to only include valid CURLOPT_* integer keys
        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        // Set up SSE-specific headers and options
        $sseOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array_merge(
                $this->extractHttpHeaders($curlOnlyOptions),
                [
                    'Accept: text/event-stream',
                    'Cache-Control: no-cache',
                    'Connection: keep-alive',
                ]
            ),
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($responseStream, &$sseResponse, $onEvent): int {
                fwrite($responseStream, $data);
                
                // If we have an SSE response and event callback, parse events in real-time
                if ($sseResponse !== null && $onEvent !== null) {
                    try {
                        $events = $sseResponse->parseEvents($data);
                        foreach ($events as $event) {
                            $onEvent($event);
                        }
                    } catch (Exception $e) {
                        // Continue processing even if event parsing fails
                        error_log("SSE event parsing error: " . $e->getMessage());
                    }
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
            $sseOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($promise, $responseStream, &$headerAccumulator, &$sseResponse, $onError): void {
                if ($promise->isCancelled()) {
                    fclose($responseStream);
                    return;
                }

                if ($error !== null) {
                    fclose($responseStream);
                    if ($onError !== null) {
                        $onError($error);
                    }
                    $promise->reject(new HttpStreamException("SSE connection failed: {$error}"));
                } else {
                    rewind($responseStream);
                    $stream = new Stream($responseStream);

                    $formattedHeaders = $this->formatHeaders($headerAccumulator);
                    $sseResponse = new SSEResponse($stream, $httpCode ?? 200, $formattedHeaders);

                    if ($httpVersion !== null) {
                        $sseResponse->setHttpVersion($httpVersion);
                    }

                    $promise->resolve($sseResponse);
                }
            }
        );

        // Initialize SSE response early for real-time event parsing
        $sseResponse = new SSEResponse(new Stream($responseStream), 200, []);

        $promise->setCancelHandler(function () use ($requestId, $responseStream): void {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
            if (is_resource($responseStream)) {
                fclose($responseStream);
            }
        });

        return $promise;
    }

    /**
     * Wraps the event callback to handle last event ID tracking.
     */
    private function wrapEventCallback(?callable $onEvent, SSEConnectionState $state): ?callable
    {
        if ($onEvent === null) {
            return null;
        }

        return function (SSEEvent $event) use ($onEvent, $state) {
            // Track last event ID for reconnection
            if ($event->id !== null) {
                $state->setLastEventId($event->id);
            }

            // Handle retry directive
            if ($event->retry !== null) {
                $state->setRetryInterval($event->retry);
            }

            // Call the original callback
            $onEvent($event);
        };
    }

    /**
     * Wraps the error callback to handle reconnection logic.
     */
    private function wrapErrorCallback(
        ?callable $onError, 
        SSEConnectionState $state, 
        CancellablePromise $mainPromise
    ): ?callable {
        return function (string $error) use ($onError, $state, $mainPromise) {
            // Call the original error callback
            if ($onError !== null) {
                $onError($error);
            }

            // Mark connection as failed for reconnection logic
            $state->onConnectionFailed(new Exception($error));
        };
    }

    /**
     * Extracts HTTP headers from cURL options.
     */
    private function extractHttpHeaders(array $curlOptions): array
    {
        return $curlOptions[CURLOPT_HTTPHEADER] ?? [];
    }

    /**
     * Formats raw headers into structured array.
     */
    private function formatHeaders(array $headerAccumulator): array
    {
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
        return $formattedHeaders;
    }
}