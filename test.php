<?php

use Rcalicdan\FiberAsync\Api\Task;

require 'vendor/autoload.php';

try {
    echo "Starting retry functionality test...\n\n";
    
    $result = Task::run(function () {
        // Test 1: Retry with default config on a flaky endpoint
        echo "=== Test 1: Default Retry (3 attempts) ===\n";
        
        try {
            $startTime = microtime(true);
            
            // This endpoint randomly returns 500 errors
            $response1 = await(fetch('https://httpbin.org/status/500', [
                'retry' => true, // Enable default retry
                'headers' => ['User-Agent' => 'Retry-Test/1.0']
            ]));
            
            $duration1 = microtime(true) - $startTime;
            echo "❌ Unexpected success: " . $response1->getStatusCode() . "\n";
            
        } catch (Exception $e) {
            $duration1 = microtime(true) - $startTime;
            echo "✓ Failed as expected after retries: " . $e->getMessage() . "\n";
            echo "✓ Total duration: " . round($duration1, 3) . " seconds (includes retry delays)\n\n";
        }
        
        // Test 2: Custom retry configuration
        echo "=== Test 2: Custom Retry Config ===\n";
        
        try {
            $startTime = microtime(true);
            
            $response2 = await(fetch('https://httpbin.org/status/503', [
                'retry' => [
                    'max_retries' => 2,
                    'base_delay' => 0.5, // 500ms base delay
                    'backoff_multiplier' => 1.5,
                    'retryable_status_codes' => [503, 504, 500]
                ],
                'headers' => ['User-Agent' => 'Retry-Test/1.0']
            ]));
            
        } catch (Exception $e) {
            $duration2 = microtime(true) - $startTime;
            echo "✓ Custom retry failed as expected: " . $e->getMessage() . "\n";
            echo "✓ Duration: " . round($duration2, 3) . " seconds\n\n";
        }
        
        // Test 3: Successful retry scenario
        echo "=== Test 3: Eventually Successful Request ===\n";
        
        try {
            $startTime = microtime(true);
            
            // Use a reliable endpoint that should succeed
            $response3 = await(fetch('https://httpbin.org/status/200', [
                'retry' => [
                    'max_retries' => 3,
                    'base_delay' => 0.1
                ],
                'headers' => ['User-Agent' => 'Retry-Test/1.0']
            ]));
            
            $duration3 = microtime(true) - $startTime;
            echo "✅ Success with retry enabled: " . $response3->getStatusCode() . "\n";
            echo "✓ Duration: " . round($duration3, 3) . " seconds\n\n";
            
        } catch (Exception $e) {
            echo "❌ Unexpected failure: " . $e->getMessage() . "\n\n";
        }
        
        // Test 4: Network timeout with retry
        echo "=== Test 4: Timeout with Retry ===\n";
        
        try {
            $startTime = microtime(true);
            
            $response4 = await(fetch('https://httpbin.org/delay/10', [
                'timeout' => 2, // 2 second timeout
                'retry' => [
                    'max_retries' => 2,
                    'base_delay' => 0.2,
                    'retryable_status_codes' => [408, 500, 502, 503, 504]
                ],
                'headers' => ['User-Agent' => 'Retry-Test/1.0']
            ]));
            
        } catch (Exception $e) {
            $duration4 = microtime(true) - $startTime;
            echo "✓ Timeout with retry handled: " . $e->getMessage() . "\n";
            echo "✓ Duration: " . round($duration4, 3) . " seconds\n";
        }
        
        return [
            'test1_duration' => $duration1 ?? 0,
            'test2_duration' => $duration2 ?? 0,
            'test3_duration' => $duration3 ?? 0,
            'test4_duration' => $duration4 ?? 0
        ];
    });
    
    echo "\n📈 Retry Test Results:\n";
    print_r($result);
    
} catch (Exception $e) {
    echo "❌ Retry test failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}