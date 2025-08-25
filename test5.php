<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

Task::run(function () {
    $handler = Http::enableTesting();

    // =================================================================
    // Test Case 1: Verifying Predicted Failure
    // =================================================================
    echo "--- Test Case 1: Verifying Predicted Failure --- \n";
    echo "Mock is set to succeed on the 10th attempt.\n";
    echo "HTTP client is set to retry 5 times (6 total attempts).\n";
    echo "Expected outcome: Failure.\n\n";

    $handler->reset();
    $url_fail = 'https://api.example.com/data-fail';

    Http::mock('GET')
        ->url($url_fail)
        ->rateLimitedUntilAttempt(10)
        ->register()
    ;

    try {
        $start = microtime(true);
        $response = await(Http::fetch($url_fail, [
            'retry' => [
                'max_retries' => 5,
                'base_delay' => 0.01,
                'backoff_multiplier' => 1,
            ],
        ]));
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo 'Success! Response: '.$response->body().' | Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed as expected.\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    }

    try {
        $handler->assertRequestCount(6);
        echo "Assertion successful: Exactly 6 requests were made.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 2: A Successful Retry Scenario
    // =================================================================
    echo "\n\n--- Test Case 2: A Successful Retry Scenario --- \n";
    echo "Mock is set to succeed on the 4th attempt.\n";
    echo "HTTP client is set to retry 5 times (6 total attempts).\n";
    echo "Expected outcome: Success.\n\n";

    $handler->reset();
    $url_success = 'https://api.example.com/data-success';

    Http::mock('GET')
        ->url($url_success)
        ->rateLimitedUntilAttempt(4)
        ->register()
    ;

    try {
        $start = microtime(true);
        $response = await(Http::fetch($url_success, [
            'retry' => [
                'max_retries' => 5,
                'base_delay' => 0.01,
                'backoff_multiplier' => 1,
            ],
        ]));
        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Success!\n";
        echo 'Response Body: '.$response->body()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo 'Request failed unexpectedly: '.$e->getMessage().' | Elapsed: '.$elapsed."s\n";
    }

    try {
        $handler->assertRequestCount(4);
        echo "Assertion successful: Exactly 4 requests were made.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 3: Testing Generic Failures with failUntilAttempt
    // =================================================================
    echo "\n\n--- Test Case 3: Testing Generic Failures with failUntilAttempt --- \n";
    echo "Mock is set to fail 2 times and succeed on the 3rd attempt.\n";
    echo "HTTP client is set to retry 3 times (4 total attempts).\n";
    echo "Expected outcome: Success.\n\n";

    $handler->reset();
    $url_generic_fail = 'https://api.example.com/data-generic-fail';

    Http::mock('GET')
        ->url($url_generic_fail)
        ->failUntilAttempt(3, 'Connection failed')
        ->register()
    ;

    try {
        $start = microtime(true);
        $response = await(Http::fetch($url_generic_fail, [
            'retry' => [
                'max_retries' => 3,
                'base_delay' => 0.01,
                'backoff_multiplier' => 1,
            ],
        ]));
        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Success!\n";
        echo 'Response Body: '.$response->body()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo 'Request failed unexpectedly: '.$e->getMessage().' | Elapsed: '.$elapsed."s\n";
    }

    try {
        $handler->assertRequestCount(3);
        echo "Assertion successful: Exactly 3 requests were made.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 4: Verifying 'max_retries = 3' results in 4 total attempts
    // =================================================================
    echo "\n\n--- Test Case 4: Verifying 'max_retries = 3' results in 4 total attempts --- \n";
    echo "Mock is set to succeed on the 5th attempt.\n";
    echo "HTTP client is set to retry 3 times (4 total attempts).\n";
    echo "Expected outcome: Failure after exactly 4 attempts.\n\n";

    $handler->reset();
    $url_retry_count = 'https://api.example.com/data-retry-count';

    Http::mock('GET')
        ->url($url_retry_count)
        ->failUntilAttempt(5, 'Connection failed')
        ->register()
    ;

    try {
        $start = microtime(true);
        $response = await(Http::fetch($url_retry_count, [
            'retry' => [
                'max_retries' => 3,
                'base_delay' => 0.01,
                'backoff_multiplier' => 1,
            ],
        ]));
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo 'Success! Response: '.$response->body().' | Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed as expected.\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    }

    try {
        $handler->assertRequestCount(4);
        echo "Assertion successful: Exactly 4 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 5: Testing Mixed Failures with failWithSequence
    // =================================================================
    echo "\n\n--- Test Case 5: Testing Mixed Failures with failWithSequence --- \n";
    echo "Mock is set to fail with 3 different errors, then succeed on the 4th attempt.\n";
    echo "HTTP client is set to retry 5 times (6 total attempts).\n";
    echo "Expected outcome: Success after exactly 4 attempts.\n\n";

    $handler->reset();
    $url_sequence_fail = 'https://api.example.com/data-sequence-fail';

    $failures = [
        'Connection refused',
        ['status' => 503, 'error' => 'Service Unavailable'],
        ['error' => 'Upstream service timed out', 'retryable' => true],
    ];

    Http::mock('POST')
        ->url($url_sequence_fail)
        ->failWithSequence($failures, ['message' => 'System recovered!'])
        ->register()
    ;

    try {
        $start = microtime(true);
        $response = await(Http::fetch($url_sequence_fail, [
            'method' => 'POST',
            'retry' => [
                'max_retries' => 5,
                'base_delay' => 0.01,
                'backoff_multiplier' => 1,
            ],
        ]));
        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Success!\n";
        echo 'Response Body: '.$response->body()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed unexpectedly.\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    }

    try {
        $handler->assertRequestCount(4);
        echo "Assertion successful: Exactly 4 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 6: Verifying Retry Logic with the Http::request() Builder
    // =================================================================
    echo "\n\n--- Test Case 6: Verifying Retry Logic with the Http::request() Builder --- \n";
    echo "Mock is set to fail 2 times and succeed on the 3rd attempt.\n";
    echo "HTTP client uses the request builder with retry(3).\n";
    echo "Expected outcome: Success after exactly 3 attempts.\n\n";

    $handler->reset();
    $url_builder_test = 'https://api.example.com/data-builder-test';

    Http::mock('POST')
        ->url($url_builder_test)
        ->failUntilAttempt(3)
        ->register()
    ;

    try {
        $start = microtime(true);
        $response = await(
            Http::request()
                ->retry(maxRetries: 3, baseDelay: 0.01)
                ->post($url_builder_test, ['user_id' => 123])
        );
        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Success!\n";
        echo 'Response Body: '.$response->body()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed unexpectedly.\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    }

    try {
        $handler->assertRequestCount(3);
        echo "Assertion successful: Exactly 3 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 7: Verifying Advanced Retries with the Request Builder
    // =================================================================
    echo "\n\n--- Test Case 7: Verifying Advanced Retries with the Request Builder --- \n";
    echo "Mock is set to fail twice with different errors, then succeed on the 3rd attempt.\n";
    echo "HTTP client uses the request builder with retry(3).\n";
    echo "Expected outcome: Success after exactly 3 attempts.\n\n";

    // Reset the handler for our final test.
    $handler->reset();
    $url_builder_sequence_test = 'https://api.example.com/data-builder-sequence-test';

    // Define the sequence of failures, which is shorter for this test.
    $failures = [
        'Connection refused', // Attempt 1: Fails with a generic error string.
        ['status' => 503, 'error' => 'Service Temporarily Unavailable'], // Attempt 2: Fails with a 503.
    ];

    // The mock builder will create 2 failing mocks, and then the builder
    // itself becomes the 3rd (successful) mock.
    Http::mock('POST')
        ->url($url_builder_sequence_test)
        ->failWithSequence($failures, ['status' => 'ok', 'message' => 'Builder request successful!'])
        ->register()
    ;

    try {
        $start = microtime(true);

        // Build and send the request using the fluent builder interface.
        $response = await(
            Http::request()
                ->retry(maxRetries: 3, baseDelay: 0.01) // Allow up to 1 initial + 3 retries
                ->post($url_builder_sequence_test, ['action' => 'submit'])
        );

        $end = microtime(true);
        $elapsed = round($end - $start, 2);

        echo "Success!\n";
        echo 'Response Body: '.$response->body()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed unexpectedly.\n";
        echo 'Error: '.$e->getMessage()."\n";
        echo 'Elapsed: '.$elapsed."s\n";
    }

    // Assert that exactly 3 requests were made (1 initial + 2 retries)
    try {
        $handler->assertRequestCount(3);
        echo "Assertion successful: Exactly 3 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 8: Verifying Retries with Simulated Network Failures
    // =================================================================
    echo "\n\n--- Test Case 8: Verifying Retries with Simulated Network Failures --- \n";
    echo "Network simulator is set to cause a 100% retryable failure rate.\n";
    echo "HTTP client is set to retry 3 times (4 total attempts).\n";
    echo "Expected outcome: Failure after exactly 4 attempts due to simulated network errors.\n\n";

    // Reset the handler for this new test.
    $handler->reset();
    $url_network_sim_test = 'https://api.example.com/data-network-sim-test';

    // Enable the network simulator to always inject a retryable failure.
    $handler->enableNetworkSimulation([
        'retryable_failure_rate' => 1.0, // 100% chance of a retryable network error
        'default_delay' => 0.01,         // Add a tiny delay to each simulated request
    ]);

    // We still provide a success mock. If the simulation were not active, this would be returned.
    // In this test, it will never be reached.
    Http::mock('GET')
        ->url($url_network_sim_test)
        ->respondWithStatus(200)
        ->persistent()
        ->json(['message' => 'This should not be seen!'])
        ->register()
    ;

    try {
        $start = microtime(true);

        // This request will be intercepted by the NetworkSimulator before it ever hits the mock.
        $response = await(Http::fetch($url_network_sim_test, [
            'retry' => [
                'max_retries' => 3, // 1 initial + 3 retries = 4 total attempts
                'base_delay' => 0.01,
                'backoff_multiplier' => 1,
            ],
        ]));

        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo 'Request succeeded unexpectedly! | Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed as expected due to simulated network conditions.\n";
        // We check that the error message contains the expected simulation text.
        if (str_contains($e->getMessage(), '(network simulation)')) {
            echo "Error message correctly indicates a simulated failure.\n";
        } else {
            echo 'Error message was not from the simulator: '.$e->getMessage()."\n";
        }
        echo 'Elapsed: '.$elapsed."s\n";
    }

    // Assert that exactly 4 requests were made before giving up.
    try {
        $handler->assertRequestCount(4);
        echo "Assertion successful: Exactly 4 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }

    // =================================================================
    // Test Case 9: Verifying Retries with Simulated Network Failures
    // =================================================================
    echo "\n\n--- Test Case 9: Verifying Retries with Simulated Network Failures with Request Builder --- \n";
    echo "Network simulator is set to cause a 100% retryable failure rate.\n";
    echo "HTTP client is set to retry 3 times (4 total attempts).\n";
    echo "Expected outcome: Failure after exactly 4 attempts due to simulated network errors.\n\n";

    // Reset the handler for this new test.
    $handler->reset();
    $url_network_sim_test = 'https://api.example.com/data-network-sim-test';

    // Enable the network simulator to always inject a retryable failure.
    $handler->enableNetworkSimulation([
        'retryable_failure_rate' => 1.0, // 100% chance of a retryable network error
        'default_delay' => 0.01,         // Add a tiny delay to each simulated request
    ]);

    // We still provide a success mock. If the simulation were not active, this would be returned.
    // In this test, it will never be reached.
    Http::mock('GET')
        ->url($url_network_sim_test)
        ->respondWithStatus(200)
        ->persistent()
        ->json(['message' => 'This should not be seen!'])
        ->register()
    ;

    try {
        $start = microtime(true);

        // This request will be intercepted by the NetworkSimulator before it ever hits the mock.
        $response = await(Http::request()->retry(3, 0.1, 1)->get($url_network_sim_test));

        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo 'Request succeeded unexpectedly! | Elapsed: '.$elapsed."s\n";
    } catch (Exception $e) {
        $end = microtime(true);
        $elapsed = round($end - $start, 2);
        echo "Request failed as expected due to simulated network conditions.\n";
        // We check that the error message contains the expected simulation text.
        if (str_contains($e->getMessage(), '(network simulation)')) {
            echo "Error message correctly indicates a simulated failure.\n";
        } else {
            echo 'Error message was not from the simulator: '.$e->getMessage()."\n";
        }
        echo 'Elapsed: '.$elapsed."s\n";
    }

    // Assert that exactly 4 requests were made before giving up.
    try {
        $handler->assertRequestCount(4);
        echo "Assertion successful: Exactly 4 requests were made as expected.\n";
    } catch (Exception $e) {
        echo 'Assertion failed: '.$e->getMessage()."\n";
    }
});
