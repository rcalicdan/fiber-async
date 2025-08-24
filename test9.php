<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;

require 'vendor/autoload.php';

echo "====== Mocked Integration Tests for Interceptors ======\n";
echo "NOTE: This script uses the testing framework and makes NO real network requests.\n\n";

Task::run(function () {
    // Enable testing mode. This is the only handler we need.
    $handler = Http::testing();

    try {
        // =================================================================
        // Test Case 1: Modifying a Request with interceptRequest
        // =================================================================
        echo "--- Test Case 1: Modifying a Request with interceptRequest --- \n";
        echo "Goal: Add a 'X-Request-ID' header and verify it was sent.\n\n";
        
        $handler->reset();
        $requestId = 'fiber-' . uniqid();
        $url = 'https://api.internal/resource';

        Http::mock('POST')
            ->url($url)
            ->respondWithStatus(201)
            ->json(['status' => 'created', 'id' => 123])
            ->register();

        $requestInterceptor = function (Request $request) use ($requestId) {
            echo "   -> Request Interceptor Fired: Adding header 'X-Request-ID: {$requestId}'\n";
            return $request->withHeader('X-Request-ID', $requestId);
        };

        await(
            Http::request()
                ->interceptRequest($requestInterceptor)
                ->post($url, ['name' => 'test'])
        );

        echo "   Request sent. Verifying history...\n";
        
        $history = $handler->getRequestHistory();
        $sentRequest = $history[0];
        $sentHeaders = $sentRequest->options[CURLOPT_HTTPHEADER] ?? [];

        $headerFound = false;
        $expectedHeader = "X-Request-ID: {$requestId}";
        foreach ($sentHeaders as $header) {
            if ($header === $expectedHeader) {
                $headerFound = true;
                break;
            }
        }

        if ($headerFound) {
            echo "   ✓ SUCCESS: Request was sent with the header from the interceptor.\n";
        } else {
            echo "   ✗ FAILED: The interceptor's header was not found in the final request.\n";
        }
        $handler->assertRequestCount(1);


        // =================================================================
        // Test Case 2: Modifying a Response with interceptResponse
        // =================================================================
        echo "\n--- Test Case 2: Modifying a Response with interceptResponse --- \n";
        echo "Goal: Add a new field to the JSON body and a new header to the response.\n\n";

        $handler->reset();
        $url_json = 'https://api.internal/json';

        Http::mock('GET')
            ->url($url_json)
            ->json(['slideshow' => ['author' => 'Original Author']])
            ->register();

        $responseInterceptor = function (Response $response) {
            echo "   -> Response Interceptor Fired: Modifying response...\n";
            $modifiedResponse = $response->withHeader('X-Processed-By', 'FiberAsync-Interceptor');
            $body = $modifiedResponse->json();
            $body['intercepted'] = true;
            return $modifiedResponse->withBody(Http::createStream(json_encode($body)));
        };

        $response = await(
            Http::request()
                ->interceptResponse($responseInterceptor)
                ->get($url_json)
        );

        $data = $response->json();

        if ($response->header('X-Processed-By') === 'FiberAsync-Interceptor' && isset($data['intercepted']) && $data['intercepted'] === true) {
            echo "   ✓ SUCCESS: Response was successfully modified by the interceptor.\n";
            echo "   New Body: " . json_encode($data) . "\n";
        } else {
            echo "   ✗ FAILED: Response was not modified as expected.\n";
        }
        $handler->assertRequestCount(1);


        // =================================================================
        // Test Case 3: Advanced - Transparent Token Refresh on 401
        // =================================================================
        echo "\n--- Test Case 3: Advanced - Transparent Token Refresh on 401 --- \n";
        echo "Goal: Catch a 401, refresh a token, and retry the original request.\n\n";

        $handler->reset();

        $authManager = new class {
            public string $token = 'expired-token';

            public function refreshToken(): void {
                $this->token = 'new-valid-token-' . uniqid();
                echo "   -> Auth Manager: Token has been refreshed to '{$this->token}'.\n";
            }

            public function createAuthInterceptor(): callable {
                return function (Response $response) {
                    if ($response->status() !== 401) return $response;
                    echo "   -> Response Interceptor: Caught 401 Unauthorized! Refreshing token...\n";
                    $this->refreshToken();
                    echo "   -> Response Interceptor: Transparently retrying the original request with the new token...\n";
                    return Http::request()->bearerToken($this->token)->get('https://api.internal/headers');
                };
            }
        };
        
        Http::mock('GET')->url('https://api.internal/status/401')->respondWithStatus(401)->register();
        Http::mock('GET')->url('https://api.internal/headers')->json(['message' => 'authenticated'])->register();

        $finalResponse = await(
            Http::request()
                ->bearerToken($authManager->token)
                ->interceptResponse($authManager->createAuthInterceptor())
                ->get('https://api.internal/status/401')
        );

        $data = $finalResponse->json();
        
        if ($finalResponse->status() === 200 && isset($data['message']) && $data['message'] === 'authenticated') {
            echo "   ✓ SUCCESS: The application received a 200 OK response, even though the first attempt failed.\n";
        } else {
            echo "   ✗ FAILED: The transparent retry did not work as expected.\n";
        }

        $handler->assertRequestCount(2);
        echo "   ✓ Assertion successful: Exactly 2 requests were made behind the scenes.\n";

        // =================================================================
        // Test Case 4: Chaining Multiple Interceptors
        // =================================================================
        echo "\n--- Test Case 4: Chaining Multiple Interceptors --- \n";
        echo "Goal: Verify that multiple interceptors execute in order.\n\n";

        $handler->reset();
        $url_multi = 'https://api.internal/multi-intercept';

        Http::mock('GET')->url($url_multi)->json(['original' => true])->register();

        // Define Request Interceptors
        $reqInterceptor1 = function (Request $request) {
            echo "   -> Request Interceptor #1 Fired.\n";
            return $request->withHeader('X-Step-1', 'Set-by-1');
        };
        $reqInterceptor2 = function (Request $request) {
            echo "   -> Request Interceptor #2 Fired.\n";
            $step1Header = $request->getHeaderLine('X-Step-1');
            return $request
                ->withHeader('X-Step-1', $step1Header . ';Modified-by-2')
                ->withHeader('X-Step-2', 'Set-by-2');
        };

        // Define Response Interceptors
        $resInterceptor1 = function (Response $response) {
            echo "   -> Response Interceptor #1 Fired.\n";
            $body = $response->json();
            $body['step1_complete'] = true;
            return $response->withBody(Http::createStream(json_encode($body)));
        };
        $resInterceptor2 = function (Response $response) {
            echo "   -> Response Interceptor #2 Fired.\n";
            $body = $response->json();
            $body['step2_complete'] = true;
            return $response
                ->withHeader('X-Processed-By', 'Interceptor-Chain')
                ->withBody(Http::createStream(json_encode($body)));
        };

        // Chain all interceptors onto a single request
        $finalResponse = await(
            Http::request()
                ->interceptRequest($reqInterceptor1)
                ->interceptRequest($reqInterceptor2)
                ->interceptResponse($resInterceptor1)
                ->interceptResponse($resInterceptor2)
                ->get($url_multi)
        );

        echo "\n   Verifying final state...\n";

        // Verify the request that was sent
        $sentRequest = $handler->getRequestHistory()[0];
        $sentHeaders = $sentRequest->options[CURLOPT_HTTPHEADER] ?? [];
        $finalStep1Header = '';
        $finalStep2Header = '';
        foreach($sentHeaders as $h) {
            if(str_starts_with($h, 'X-Step-1:')) $finalStep1Header = $h;
            if(str_starts_with($h, 'X-Step-2:')) $finalStep2Header = $h;
        }

        if ($finalStep1Header === 'X-Step-1: Set-by-1;Modified-by-2' && $finalStep2Header === 'X-Step-2: Set-by-2') {
            echo "   ✓ SUCCESS: Request headers were correctly modified in sequence.\n";
        } else {
            echo "   ✗ FAILED: Request headers are incorrect.\n";
        }

        // Verify the final response that was received
        $finalData = $finalResponse->json();
        if ($finalResponse->header('X-Processed-By') === 'Interceptor-Chain' &&
            ($finalData['original'] ?? false) &&
            ($finalData['step1_complete'] ?? false) &&
            ($finalData['step2_complete'] ?? false)
        ) {
            echo "   ✓ SUCCESS: Response body and headers were correctly modified in sequence.\n";
        } else {
            echo "   ✗ FAILED: Final response is incorrect.\n";
        }

        $handler->assertRequestCount(1);
        echo "   ✓ Assertion successful: Exactly 1 request was made.\n";

    } catch (Exception $e) {
        echo "\n!!!!!! A TEST CASE FAILED UNEXPECTEDLY !!!!!!\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    } finally {
        Http::stopTesting();
    }
});

echo "\n====== Mocked Interceptor Testing Complete ======\n";