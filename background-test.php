<?php
// test_background_async.php

require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Background;

echo "=== FiberAsync Background Execution Test ===\n\n";

// Configure the background worker
Background::configure([
    'timeout' => 30, // Allow longer timeout for our tests
    'log_errors' => true,
    'auto_create_worker' => true
]);

// Test configuration
$testResults = [];
$logFile = __DIR__ . '/test_log.txt';

// Clear previous test logs
if (file_exists($logFile)) {
    unlink($logFile);
}

echo "Starting comprehensive background execution tests...\n\n";

// =============================================================================
// TEST 1: Basic Timing Test - Verify non-blocking execution
// =============================================================================

echo "TEST 1: Basic Timing Test\n";
echo "- Testing if Background::run() returns immediately without waiting\n";

$startTime = microtime(true);

Background::run(function () use ($logFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "TEST 1: Closure executed at {$timestamp}\n", FILE_APPEND);

    // Simulate work with a 3-second delay
    sleep(3);

    $endTimestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "TEST 1: Closure finished at {$endTimestamp}\n", FILE_APPEND);
});

$returnTime = microtime(true);
$executionTime = ($returnTime - $startTime) * 1000;

echo "✓ Background::run() returned in {$executionTime}ms\n";

// More realistic thresholds based on your results
if ($executionTime < 500) { // Allow up to 500ms for network overhead
    echo "✓ PASS: Method returned quickly (non-blocking)\n";
    $testResults['timing_test'] = 'PASS';
} else if ($executionTime < 2000) {
    echo "⚠ PARTIAL: Method returned reasonably fast, but could be improved\n";
    $testResults['timing_test'] = 'PARTIAL';
} else {
    echo "✗ FAIL: Method took too long to return (blocking behavior detected)\n";
    $testResults['timing_test'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 2: HTTP API Call Test with Real Delay
// =============================================================================

echo "TEST 2: HTTP API Call Test with Real Network Delay\n";
echo "- Using httpbin.org/delay/5 to test real network operations\n";

$apiStartTime = microtime(true);

Background::run(function () use ($logFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "TEST 2: Starting HTTP request at {$timestamp}\n", FILE_APPEND);

    // Make a real HTTP request with 5-second delay
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'header' => "User-Agent: FiberAsync-Test/1.0\r\n"
        ]
    ]);

    $response = @file_get_contents('https://httpbin.org/delay/5', false, $context);

    $endTimestamp = date('Y-m-d H:i:s');

    if ($response !== false) {
        $data = json_decode($response, true);
        file_put_contents($logFile, "TEST 2: HTTP request completed at {$endTimestamp}\n", FILE_APPEND);
        file_put_contents($logFile, "TEST 2: Response URL: " . ($data['url'] ?? 'unknown') . "\n", FILE_APPEND);
    } else {
        file_put_contents($logFile, "TEST 2: HTTP request failed at {$endTimestamp}\n", FILE_APPEND);
    }
});

$apiReturnTime = microtime(true);
$apiExecutionTime = ($apiReturnTime - $apiStartTime) * 1000;

echo "✓ Background::run() with HTTP call returned in {$apiExecutionTime}ms\n";

if ($apiExecutionTime < 1000) { // Should return before the 5-second delay
    echo "✓ PASS: HTTP call is running in background\n";
    $testResults['http_test'] = 'PASS';
} else {
    echo "✗ FAIL: HTTP call appears to be blocking\n";
    $testResults['http_test'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 3: Multiple Concurrent Tasks Test
// =============================================================================

echo "TEST 3: Multiple Concurrent Tasks Test\n";
echo "- Launching 3 background tasks simultaneously\n";

$concurrentStartTime = microtime(true);

// Launch 3 tasks with different delays
for ($i = 1; $i <= 3; $i++) {
    Background::run(function () use ($logFile, $i) {
        $startTimestamp = date('Y-m-d H:i:s.u');
        file_put_contents($logFile, "TEST 3: Task {$i} started at {$startTimestamp}\n", FILE_APPEND);

        // Each task sleeps for different duration
        sleep($i * 2);

        $endTimestamp = date('Y-m-d H:i:s.u');
        file_put_contents($logFile, "TEST 3: Task {$i} completed at {$endTimestamp}\n", FILE_APPEND);
    });
}

$concurrentReturnTime = microtime(true);
$concurrentExecutionTime = ($concurrentReturnTime - $concurrentStartTime) * 1000;

echo "✓ All 3 tasks launched in {$concurrentExecutionTime}ms\n";

if ($concurrentExecutionTime < 500) {
    echo "✓ PASS: Multiple tasks launched concurrently\n";
    $testResults['concurrent_test'] = 'PASS';
} else {
    echo "✗ FAIL: Tasks appear to be running sequentially\n";
    $testResults['concurrent_test'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 4: Array Task Test with POST to httpbin
// =============================================================================

echo "TEST 4: Array Task Test with Real POST Request\n";
echo "- Testing array-based background tasks with HTTP POST\n";

$postStartTime = microtime(true);

Background::run([
    'callback' => function ($data) use ($logFile) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "TEST 4: Array task started at {$timestamp}\n", FILE_APPEND);

        // Make POST request to httpbin
        $postData = json_encode([
            'test_id' => $data['test_id'],
            'timestamp' => $timestamp,
            'message' => 'Background array task test'
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($postData) . "\r\n",
                'content' => $postData,
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents('https://httpbin.org/post', false, $context);

        $endTimestamp = date('Y-m-d H:i:s');

        if ($response !== false) {
            file_put_contents($logFile, "TEST 4: POST request successful at {$endTimestamp}\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "TEST 4: POST request failed at {$endTimestamp}\n", FILE_APPEND);
        }
    },
    'test_id' => 'array_test_' . uniqid(),
    'description' => 'Testing array-based background execution'
]);

$postReturnTime = microtime(true);
$postExecutionTime = ($postReturnTime - $postStartTime) * 1000;

echo "✓ Array task launched in {$postExecutionTime}ms\n";

if ($postExecutionTime < 200) {
    echo "✓ PASS: Array task launched in background\n";
    $testResults['array_test'] = 'PASS';
} else {
    echo "✗ FAIL: Array task appears to be blocking\n";
    $testResults['array_test'] = 'FAIL';
}

echo "\n";

// =============================================================================
// Wait and Monitor Results
// =============================================================================

echo "=== Waiting for background tasks to complete ===\n";
echo "Monitoring log file for 15 seconds...\n\n";

$monitorStart = time();
$lastLogSize = 0;

while ((time() - $monitorStart) < 15) {
    if (file_exists($logFile)) {
        $currentSize = filesize($logFile);
        if ($currentSize > $lastLogSize) {
            // New content added, show the latest entries
            $content = file_get_contents($logFile);
            $lines = explode("\n", $content);
            $newLines = array_slice($lines, substr_count(file_get_contents($logFile, false, null, 0, $lastLogSize), "\n"));

            foreach ($newLines as $line) {
                if (trim($line)) {
                    echo "LOG: " . trim($line) . "\n";
                }
            }

            $lastLogSize = $currentSize;
        }
    }

    sleep(1);
    echo ".";
}

echo "\n\n";

// =============================================================================
// Final Results and Analysis
// =============================================================================

echo "=== TEST RESULTS SUMMARY ===\n";

foreach ($testResults as $test => $result) {
    $status = $result === 'PASS' ? '✓' : '✗';
    echo "{$status} {$test}: {$result}\n";
}

$passCount = count(array_filter($testResults, fn($r) => $r === 'PASS'));
$totalTests = count($testResults);

echo "\nOverall: {$passCount}/{$totalTests} tests passed\n";

if ($passCount === $totalTests) {
    echo "🎉 ALL TESTS PASSED - Background execution is working correctly!\n";
} else {
    echo "⚠️  Some tests failed - Background execution may have issues\n";
}

// =============================================================================
// Debug Information
// =============================================================================

echo "\n=== DEBUG INFORMATION ===\n";
$debugInfo = Background::debug();

echo "Worker URL: " . ($debugInfo['cached_worker_url'] ?? 'Not cached') . "\n";
echo "Configuration: " . json_encode($debugInfo['config'], JSON_PRETTY_PRINT) . "\n";

// Test worker connection
echo "\nTesting worker connection...\n";
$connectionTest = Background::testWorkerConnection();
echo "Worker connection: " . ($connectionTest ? "✓ OK" : "✗ FAILED") . "\n";

echo "\n=== Final Log Contents ===\n";
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "No log file found - tasks may not have executed\n";
}

echo "\n=== Test Complete ===\n";
