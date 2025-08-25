<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Request;
use Rcalicdan\FiberAsync\Http\Response;

require 'vendor/autoload.php';

echo "====== Integration Tests for Interceptors ======\n";
echo "NOTE: This script makes REAL network requests to httpbin.org.\n\n";

Task::run(function () {
    $handler = Http::startTesting(); // Use handler only for assertions, not mocking.
    $handler->setAllowPassthrough(true); // Ensure real network calls can be made.

    try {
        // =================================================================
        // Test Case 1: Modifying a Request with interceptRequest
        // =================================================================
        echo "--- Test Case 1: Modifying a Request with interceptRequest --- \n";
        echo "Goal: Add a 'X-Test-Header' header before sending.\n\n";

        $requestId = 'Hello-from-FiberAsync-'.rand(1000, 9999);

        $requestInterceptor = function (Request $request) use ($requestId) {
            echo "   -> Request Interceptor Fired: Adding header 'X-Test-Header: {$requestId}'\n";

            return $request->withHeader('X-Test-Header', $requestId);
        };

        $response = await(
            Http::request()
                ->interceptRequest($requestInterceptor)
                ->get('https://httpbin.org/headers')
        );

        $data = $response->json();

        if (isset($data['headers']['X-Test-Header']) && $data['headers']['X-Test-Header'] === $requestId) {
            echo "   ✓ SUCCESS: Server received the custom header added by the interceptor.\n";
        } else {
            echo "   ✗ FAILED: Custom header was not found in the server's response.\n";
            print_r($data['headers']);
        }

        // =================================================================
        // Test Case 2: Modifying a Response with interceptResponse
        // =================================================================
        echo "\n--- Test Case 2: Modifying a Response with interceptResponse --- \n";
        echo "Goal: Add a new field to the JSON body and a new header to the response.\n\n";

        $responseInterceptor = function (Response $response) {
            echo "   -> Response Interceptor Fired: Modifying response...\n";

            // 1. Add a new header
            $modifiedResponse = $response->withHeader('X-Processed-By', 'FiberAsync-Interceptor');

            // 2. Modify the body
            $body = $modifiedResponse->json();
            $body['intercepted'] = true;
            $body['processed_at'] = date('c');

            // 3. Return the response with the new body
            return $modifiedResponse->withBody(Http::createStream(json_encode($body)));
        };

        $response = await(
            Http::request()
                ->interceptResponse($responseInterceptor)
                ->get('https://httpbin.org/json')
        );

        $data = $response->json();

        if ($response->header('X-Processed-By') === 'FiberAsync-Interceptor' && isset($data['intercepted']) && $data['intercepted'] === true) {
            echo "   ✓ SUCCESS: Response was successfully modified by the interceptor.\n";
            echo '   New Body: '.json_encode($data)."\n";
        } else {
            echo "   ✗ FAILED: Response was not modified as expected.\n";
        }

        // =================================================================
        // Test Case 3: Advanced - Transparent Token Refresh on 401
        // =================================================================
        echo "\n--- Test Case 3: Advanced - Transparent Token Refresh on 401 --- \n";
        echo "Goal: Catch a 401, refresh a token, and retry the original request.\n\n";

        $authManager = new class
        {
            public string $token = 'expired-token';

            public function refreshToken(): void
            {
                // In a real app, this would be another async HTTP call.
                $this->token = 'new-valid-token-'.uniqid();
                echo "   -> Auth Manager: Token has been refreshed to '{$this->token}'.\n";
            }

            public function createAuthInterceptor(): callable
            {
                return function (Response $response) {
                    if ($response->status() !== 401) {
                        return $response;
                    }

                    echo "   -> Response Interceptor: Caught 401 Unauthorized! Refreshing token...\n";
                    $this->refreshToken();

                    echo "   -> Response Interceptor: Transparently retrying the original request with the new token...\n";

                    return Http::request()
                        ->bearerToken($this->token)
                        ->get('https://httpbin.org/headers')
                    ;
                };
            }
        };

        // Use the handler to track how many requests were made behind the scenes.
        $handler->reset();

        // 1. Make the initial request with the expired token to an endpoint that will fail.
        $finalResponse = await(
            Http::request()
                ->bearerToken($authManager->token)
                ->interceptResponse($authManager->createAuthInterceptor())
                ->get('https://httpbin.org/status/401') // This endpoint is guaranteed to return 401
        );

        $data = $finalResponse->json();

        if ($finalResponse->status() === 200 && $data['headers']['Authorization'] === 'Bearer '.$authManager->token) {
            echo "   ✓ SUCCESS: The application received a 200 OK response, even though the first attempt failed.\n";
            echo "   ✓ The final request correctly used the new token.\n";
        } else {
            echo "   ✗ FAILED: The transparent retry did not work as expected.\n";
        }

        // Verify that two requests were made: the initial 401, and the transparent retry.
        $handler->assertRequestCount(2);
        echo "   ✓ Assertion successful: Exactly 2 requests were made behind the scenes.\n";
    } catch (Exception $e) {
        echo "\n!!!!!! A TEST CASE FAILED UNEXPECTEDLY !!!!!!\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
    } finally {
        Http::stopTesting();
    }
});

echo "\n====== Interceptor Testing Complete ======\n";
