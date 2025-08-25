<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;
use Rcalicdan\FiberAsync\Http\Stream;
use Rcalicdan\FiberAsync\Promise\Promise;

require 'vendor/autoload.php';

echo "====== Sequential Interceptor Tests (nextTick vs no nextTick) ======\n";
echo "NOTE: This script uses the testing framework and makes NO real network requests.\n\n";

Task::run(function () {
    // Enable testing mode
    $handler = Http::startTesting();

    try {
        // =================================================================
        // Test Case 1: Sequential Request Interceptors with Timing
        // =================================================================
        echo "--- Test Case 1: Sequential Request Interceptors with Timing --- \n";
        echo "Goal: Verify execution order and measure timing differences.\n\n";

        $handler->reset();
        $url = 'https://api.internal/sequential';
        $executionLog = [];

        Http::mock('POST')
            ->url($url)
            ->respondWithStatus(200)
            ->persistent()
            ->json(['processed' => true])
            ->register()
        ;

        // Create interceptors that log their execution order and timing
        $requestInterceptor1 = function (Request $request) use (&$executionLog) {
            $timestamp = microtime(true);
            $executionLog[] = 'Request Interceptor 1 - '.$timestamp;
            echo "   -> Request Interceptor #1 executed at: {$timestamp}\n";
            usleep(1000); // 1ms delay to simulate some work

            return $request->withHeader('X-Interceptor-1', 'executed');
        };

        $requestInterceptor2 = function (Request $request) use (&$executionLog) {
            $timestamp = microtime(true);
            $executionLog[] = 'Request Interceptor 2 - '.$timestamp;
            echo "   -> Request Interceptor #2 executed at: {$timestamp}\n";
            usleep(1000); // 1ms delay
            $existing = $request->getHeaderLine('X-Interceptor-1');

            return $request->withHeader('X-Interceptor-2', $existing.'+interceptor2');
        };

        $requestInterceptor3 = function (Request $request) use (&$executionLog) {
            $timestamp = microtime(true);
            $executionLog[] = 'Request Interceptor 3 - '.$timestamp;
            echo "   -> Request Interceptor #3 executed at: {$timestamp}\n";
            usleep(1000); // 1ms delay

            return $request->withHeader('X-Final-Count', '3-interceptors');
        };

        $startTime = microtime(true);

        $response = await(
            Http::request()
                ->interceptRequest($requestInterceptor1)
                ->interceptRequest($requestInterceptor2)
                ->interceptRequest($requestInterceptor3)
                ->post($url, ['test' => 'sequential'])
        );

        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        echo "   Total execution time: {$totalTime}ms\n";
        echo "   Execution log:\n";
        foreach ($executionLog as $entry) {
            echo "     - {$entry}\n";
        }

        // Verify headers were applied in sequence
        $history = $handler->getRequestHistory();
        $sentRequest = $history[0];
        $sentHeaders = $sentRequest->options[CURLOPT_HTTPHEADER] ?? [];

        $header1Found = false;
        $header2Found = false;
        $header3Found = false;

        foreach ($sentHeaders as $header) {
            if (str_contains($header, 'X-Interceptor-1: executed')) {
                $header1Found = true;
            }
            if (str_contains($header, 'X-Interceptor-2: executed+interceptor2')) {
                $header2Found = true;
            }
            if (str_contains($header, 'X-Final-Count: 3-interceptors')) {
                $header3Found = true;
            }
        }

        if ($header1Found && $header2Found && $header3Found) {
            echo "   ✓ SUCCESS: All sequential interceptors executed correctly.\n";
        } else {
            echo "   ✗ FAILED: Sequential interceptor execution failed.\n";
            echo '   Headers found: '.json_encode($sentHeaders)."\n";
        }

        // =================================================================
        // Test Case 2: Sequential Response Interceptors with Async Operations
        // =================================================================
        echo "\n--- Test Case 2: Sequential Response Interceptors with Mixed Sync/Async --- \n";
        echo "Goal: Test response interceptors that mix sync and async operations.\n\n";

        $handler->reset();
        $responseUrl = 'https://api.internal/response-sequential';
        $asyncUrl = 'https://api.internal/async-helper';
        $responseLog = [];

        Http::mock('GET')
            ->url($responseUrl)
            ->persistent()
            ->json(['original' => 'data', 'step' => 0])
            ->register()
        ;

        // Mock for the async helper request
        Http::mock('GET')
            ->url($asyncUrl)
            ->json(['async_data' => 'processed', 'timestamp' => microtime(true)])
            ->register()
        ;

        // Sync response interceptor
        $responseInterceptor1 = function (Response $response) use (&$responseLog) {
            $timestamp = microtime(true);
            $responseLog[] = 'Response Interceptor 1 (sync) - '.$timestamp;
            echo "   -> Response Interceptor #1 (sync) executed at: {$timestamp}\n";

            $body = $response->json();
            $body['step'] = 1;
            $body['sync_processed'] = true;

            return $response->withBody(Stream::fromString(json_encode($body)));
        };

        // Async response interceptor that returns a promise
        $responseInterceptor2 = function (Response $response) use (&$responseLog, $asyncUrl) {
            $timestamp = microtime(true);
            $responseLog[] = 'Response Interceptor 2 (async start) - '.$timestamp;
            echo "   -> Response Interceptor #2 (async) started at: {$timestamp}\n";

            // Return a promise by making an async HTTP request
            return Http::request()->get($asyncUrl)->then(
                function ($asyncResponse) use ($response, &$responseLog, $timestamp) {
                    $endTimestamp = microtime(true);
                    $responseLog[] = 'Response Interceptor 2 (async end) - '.$endTimestamp;
                    echo "   -> Response Interceptor #2 (async) completed at: {$endTimestamp}\n";

                    $body = $response->json();
                    $asyncData = $asyncResponse->json();

                    $body['step'] = 2;
                    $body['async_processed'] = true;
                    $body['async_duration'] = ($endTimestamp - $timestamp) * 1000;
                    $body['async_data'] = $asyncData;

                    return $response->withBody(Stream::fromString(json_encode($body)));
                }
            );
        };

        // Another sync response interceptor
        $responseInterceptor3 = function (Response $response) use (&$responseLog) {
            $timestamp = microtime(true);
            $responseLog[] = 'Response Interceptor 3 (sync final) - '.$timestamp;
            echo "   -> Response Interceptor #3 (final sync) executed at: {$timestamp}\n";

            $body = $response->json();
            $body['step'] = 3;
            $body['final_processed'] = true;

            return $response
                ->withHeader('X-Processing-Complete', 'true')
                ->withBody(Stream::fromString(json_encode($body)))
            ;
        };

        $responseStartTime = microtime(true);

        $finalResponse = await(
            Http::request()
                ->interceptResponse($responseInterceptor1)
                ->interceptResponse($responseInterceptor2)
                ->interceptResponse($responseInterceptor3)
                ->get($responseUrl)
        );

        $responseEndTime = microtime(true);
        $responseTotalTime = ($responseEndTime - $responseStartTime) * 1000;

        echo "   Response processing total time: {$responseTotalTime}ms\n";
        echo "   Response processing log:\n";
        foreach ($responseLog as $entry) {
            echo "     - {$entry}\n";
        }

        $finalData = $finalResponse->json();

        $expectedConditions = [
            $finalData['step'] === 3,
            $finalData['sync_processed'] === true,
            $finalData['async_processed'] === true,
            $finalData['final_processed'] === true,
            $finalResponse->header('X-Processing-Complete') === 'true',
        ];

        if (array_reduce($expectedConditions, fn ($carry, $condition) => $carry && $condition, true)) {
            echo "   ✓ SUCCESS: Sequential response interceptors executed correctly.\n";
            echo '   Final body: '.json_encode($finalData)."\n";
        } else {
            echo "   ✗ FAILED: Sequential response interceptor execution failed.\n";
            echo '   Final body: '.json_encode($finalData)."\n";
        }

        // =================================================================
        // Test Case 3: Response Interceptor with Promise.resolved()
        // =================================================================
        echo "\n--- Test Case 3: Response Interceptor with Promise.resolved() --- \n";
        echo "Goal: Test async response interceptor using Promise.resolved().\n\n";

        $handler->reset();
        $promiseUrl = 'https://api.internal/promise-test';

        Http::mock('GET')
            ->url($promiseUrl)
            ->json(['original' => true])
            ->persistent()
            ->register()
        ;

        $promiseInterceptor = function (Response $response) {
            echo "   -> Promise-based interceptor executing...\n";

            // Simulate async work with Promise.resolved()
            return Promise::resolved($response)->then(function ($response) {
                // Simulate some async processing time
                usleep(1500); // 1.5ms

                $body = $response->json();
                $body['promise_processed'] = true;
                $body['processed_at'] = microtime(true);

                echo "   -> Promise-based interceptor completed\n";

                return $response->withBody(Stream::fromString(json_encode($body)));
            });
        };

        $promiseStartTime = microtime(true);

        $promiseResponse = await(
            Http::request()
                ->interceptResponse($promiseInterceptor)
                ->get($promiseUrl)
        );

        $promiseEndTime = microtime(true);
        $promiseTotalTime = ($promiseEndTime - $promiseStartTime) * 1000;

        echo "   Promise-based processing time: {$promiseTotalTime}ms\n";

        $promiseData = $promiseResponse->json();
        if ($promiseData['promise_processed'] === true) {
            echo "   ✓ SUCCESS: Promise-based response interceptor worked correctly.\n";
        } else {
            echo "   ✗ FAILED: Promise-based response interceptor failed.\n";
        }

        // =================================================================
        // Test Case 4: High Concurrency Sequential Test (Enhanced Debug)
        // =================================================================
        echo "\n--- Test Case 4: High Concurrency Sequential Test (Enhanced Debug) --- \n";
        echo "Goal: Test multiple concurrent requests with sequential interceptors.\n\n";

        $handler->reset();
        $concurrentUrl = 'https://api.internal/concurrent';

        // Register mock for concurrent requests
        Http::mock('GET')
            ->url($concurrentUrl)
            ->json(['id' => 'concurrent-response'])
            ->persistent()
            ->register()
        ;

        $concurrentStartTime = microtime(true);
        $promises = [];

        // Create 10 concurrent requests, each with 3 sequential interceptors
        for ($i = 1; $i <= 10; $i++) {
            $requestId = "req-{$i}";
            echo "   Creating request {$i} with ID: {$requestId}\n";

            $promise = Http::request()
                ->interceptRequest(function (Request $request) use ($requestId) {
                    echo "     -> Request {$requestId}: Adding header X-Request-ID\n";

                    return $request->withHeader('X-Request-ID', $requestId);
                })
                ->interceptRequest(function (Request $request) use ($requestId) {
                    $existing = $request->getHeaderLine('X-Request-ID');
                    echo "     -> Request {$requestId}: Processing header (was: {$existing})\n";

                    return $request->withHeader('X-Request-ID', $existing.'-processed');
                })
                ->interceptRequest(function (Request $request) use ($requestId) {
                    echo "     -> Request {$requestId}: Adding sequence complete\n";

                    return $request->withHeader('X-Sequence', 'complete');
                })
                ->interceptResponse(function (Response $response) use ($requestId) {
                    echo "     -> Response {$requestId}: Processing response\n";

                    $body = $response->json();
                    $body['processed_by'] = $requestId.'-processed';

                    echo "     -> Response {$requestId}: Set processed_by to ".($requestId.'-processed')."\n";

                    return $response
                        ->withHeader('X-Processed-By', $requestId.'-processed')
                        ->withBody(Stream::fromString(json_encode($body)))
                    ;
                })
                ->get($concurrentUrl)
            ;

            $promises[] = $promise;
        }

        // Wait for all promises to complete
        echo "\n   Waiting for all promises to complete...\n";
        $results = await(Promise::all($promises));
        echo "   All promises completed!\n";

        $concurrentEndTime = microtime(true);
        $concurrentTotalTime = ($concurrentEndTime - $concurrentStartTime) * 1000;

        echo "   Concurrent execution time for 10 requests: {$concurrentTotalTime}ms\n";
        echo '   Average time per request: '.($concurrentTotalTime / 10)."ms\n";

        // Verify all requests were processed (with detailed debugging)
        echo "\n   Verifying results:\n";
        $allProcessed = true;
        foreach ($results as $i => $response) {
            $data = $response->json();
            $expectedId = 'req-'.($i + 1).'-processed';
            $actualId = $data['processed_by'] ?? 'NOT_FOUND';

            echo '   Request '.($i + 1).": Expected '{$expectedId}', Got '{$actualId}'\n";

            if ($actualId !== $expectedId) {
                $allProcessed = false;
                echo "     ❌ MISMATCH!\n";
                echo '     Full response: '.json_encode($data)."\n";
            } else {
                echo "     ✅ OK\n";
            }
        }

        if ($allProcessed) {
            echo "   ✓ SUCCESS: All concurrent requests with sequential interceptors processed correctly.\n";
        } else {
            echo "   ✗ FAILED: Some concurrent requests failed processing.\n";
        }

        $handler->assertRequestCount(10);
        echo "   ✓ Assertion successful: Exactly 10 requests were made.\n";
    } catch (Exception $e) {
        echo "\n!!!!!! A TEST CASE FAILED UNEXPECTEDLY !!!!!!\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
        echo 'Trace: '.$e->getTraceAsString()."\n";
    } finally {
        Http::stopTesting();
    }
});

echo "\n====== Sequential Interceptor Testing Complete ======\n";
