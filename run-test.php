<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;

// Simulate different types of operations with varying latency
function simulateApiCall($apiName, $latencySeconds)
{
    echo "[" . date('H:i:s') . "] Starting $apiName ({$latencySeconds}s latency)\n";
    await(delay($latencySeconds)); // Simulate network latency
    echo "[" . date('H:i:s') . "] Completed $apiName\n";
    return "$apiName response data";
}

function simulateDatabaseQuery($queryType, $latencySeconds)
{
    echo "[" . date('H:i:s') . "] Starting $queryType query ({$latencySeconds}s)\n";
    await(delay($latencySeconds));
    echo "[" . date('H:i:s') . "] Completed $queryType query\n";
    return "$queryType results";
}

function simulateFileProcessing($fileName, $latencySeconds)
{
    echo "[" . date('H:i:s') . "] Processing $fileName ({$latencySeconds}s)\n";
    await(delay($latencySeconds));
    echo "[" . date('H:i:s') . "] Finished processing $fileName\n";
    return "$fileName processed successfully";
}

// Sequential versions that use regular sleep() instead of await()
function simulateApiCallSync($apiName, $latencySeconds)
{
    echo "[" . date('H:i:s') . "] Starting $apiName ({$latencySeconds}s latency)\n";
    sleep($latencySeconds); // Use regular sleep for sequential execution
    echo "[" . date('H:i:s') . "] Completed $apiName\n";
    return "$apiName response data";
}

function simulateDatabaseQuerySync($queryType, $latencySeconds)
{
    echo "[" . date('H:i:s') . "] Starting $queryType query ({$latencySeconds}s)\n";
    sleep($latencySeconds);
    echo "[" . date('H:i:s') . "] Completed $queryType query\n";
    return "$queryType results";
}

function simulateFileProcessingSync($fileName, $latencySeconds)
{
    echo "[" . date('H:i:s') . "] Processing $fileName ({$latencySeconds}s)\n";
    sleep($latencySeconds);
    echo "[" . date('H:i:s') . "] Finished processing $fileName\n";
    return "$fileName processed successfully";
}

function checkConcurrencyResult($actualDuration, $expectedMaxDuration, $testName, $tolerance = 1)
{
    $isValid = $actualDuration <= ($expectedMaxDuration + $tolerance);
    $status = $isValid ? "✅ PASS" : "❌ FAIL";

    echo "--- $testName Validation ---\n";
    echo "Expected max duration: {$expectedMaxDuration}s (+ {$tolerance}s tolerance)\n";
    echo "Actual duration: {$actualDuration}s\n";
    echo "Status: $status\n";

    if (!$isValid) {
        echo "⚠️  WARNING: Tasks may have run sequentially instead of concurrently!\n";
    }

    echo "\n";
    return $isValid;
}

function testConcurrencyWithSimulation()
{
    echo "=== Testing Concurrent Execution with Validation ===\n\n";

    $allTestsPassed = true;

    // Test 1: Different latencies running concurrently
    echo "Test 1: Mixed latency operations\n";
    echo "Expected: Should complete in ~3 seconds (longest task duration)\n";

    $startTime = microtime(true);
    $results1 = Async::runConcurrent([
        fn() => simulateApiCall('UserAPI', 3),      // 3s - longest
        fn() => simulateApiCall('ProductAPI', 2),   // 2s
        fn() => simulateDatabaseQuery('Orders', 1), // 1s
        fn() => simulateFileProcessing('image.jpg', 2), // 2s
        fn() => simulateApiCall('PaymentAPI', 1)    // 1s
    ], 5);
    $duration1 = round(microtime(true) - $startTime, 1);

    $test1Passed = checkConcurrencyResult($duration1, 3, "Test 1: Mixed Latency", 2);
    $allTestsPassed = $allTestsPassed && $test1Passed;

    // Verify all tasks completed
    if (count($results1) === 5) {
        echo "✅ All 5 tasks completed successfully\n";
    } else {
        echo "❌ Expected 5 results, got " . count($results1) . "\n";
        $allTestsPassed = false;
    }
    echo "\n";

    // Test 2: Limited concurrency
    echo "Test 2: Concurrency limit test (6 tasks, limit 2)\n";
    echo "Expected: Should take ~6 seconds (3 batches × 2s each)\n";

    $startTime2 = microtime(true);
    $results2 = Async::runConcurrent([
        fn() => simulateApiCall('API-1', 2),
        fn() => simulateApiCall('API-2', 2),
        fn() => simulateApiCall('API-3', 2),
        fn() => simulateApiCall('API-4', 2),
        fn() => simulateApiCall('API-5', 2),
        fn() => simulateApiCall('API-6', 2)
    ], 2); // Only 2 concurrent tasks allowed
    $duration2 = round(microtime(true) - $startTime2, 1);

    $test2Passed = checkConcurrencyResult($duration2, 6, "Test 2: Concurrency Limit", 2);
    $allTestsPassed = $allTestsPassed && $test2Passed;

    // Additional check for concurrency limit
    if ($duration2 < 5) {
        echo "❌ WARNING: Duration too short - concurrency limit may not be working!\n";
        $allTestsPassed = false;
    } else {
        echo "✅ Concurrency limit appears to be working correctly\n";
    }
    echo "\n";

    // Test 3: Sequential vs Concurrent comparison
    echo "Test 3: Sequential vs Concurrent comparison\n";

    // Sequential execution - using sync versions that don't require Fiber context
    echo "Running 3 tasks sequentially (1s each)...\n";
    $startSequential = microtime(true);
    $sequentialResults = [];
    $sequentialResults[] = simulateApiCallSync('Sequential-1', 1);
    $sequentialResults[] = simulateApiCallSync('Sequential-2', 1);
    $sequentialResults[] = simulateApiCallSync('Sequential-3', 1);
    $durationSequential = round(microtime(true) - $startSequential, 1);
    echo "Sequential execution took: {$durationSequential}s\n\n";

    // Concurrent execution
    echo "Running same 3 tasks concurrently...\n";
    $startConcurrent = microtime(true);
    $concurrentResults = Async::runConcurrent([
        fn() => simulateApiCall('Concurrent-1', 1),
        fn() => simulateApiCall('Concurrent-2', 1),
        fn() => simulateApiCall('Concurrent-3', 1)
    ], 3);
    $durationConcurrent = round(microtime(true) - $startConcurrent, 1);
    echo "Concurrent execution took: {$durationConcurrent}s\n";

    // Validate concurrent execution
    $test3Passed = checkConcurrencyResult($durationConcurrent, 1, "Test 3: Concurrent Execution", 1);
    $allTestsPassed = $allTestsPassed && $test3Passed;

    // Calculate and validate speed improvement
    $speedImprovement = $durationSequential / max($durationConcurrent, 0.1); // Avoid division by zero
    echo "Speed improvement: " . round($speedImprovement, 1) . "x faster\n";

    if ($speedImprovement >= 2.5) {
        echo "✅ Significant speed improvement achieved\n";
    } else {
        echo "❌ Speed improvement is lower than expected\n";
        $allTestsPassed = false;
    }
    echo "\n";

    // Test 4: Alternative sequential test using runConcurrent with limit 1
    echo "Test 4: Alternative sequential test (using runConcurrent with limit 1)\n";
    echo "Expected: Should take ~3 seconds (1s × 3 tasks)\n";

    $startAltSequential = microtime(true);
    $altSequentialResults = Async::runConcurrent([
        fn() => simulateApiCall('AltSeq-1', 1),
        fn() => simulateApiCall('AltSeq-2', 1),
        fn() => simulateApiCall('AltSeq-3', 1)
    ], 1); // Limit to 1 concurrent task = sequential execution
    $durationAltSequential = round(microtime(true) - $startAltSequential, 1);

    echo "Alternative sequential execution took: {$durationAltSequential}s\n";

    // Validate that it's truly sequential
    $test4Passed = $durationAltSequential >= 2.5; // Should be close to 3 seconds
    if ($test4Passed) {
        echo "✅ Alternative sequential test confirms proper timing\n";
    } else {
        echo "❌ Alternative sequential test failed - execution was too fast\n";
        $allTestsPassed = false;
    }
    echo "\n";

    // Final summary
    echo "=== Final Test Results ===\n";
    if ($allTestsPassed) {
        echo "🎉 ALL TESTS PASSED - Concurrency is working correctly!\n";
        echo "✅ Tasks execute concurrently (not sequentially)\n";
        echo "✅ Concurrency limits are respected\n";
        echo "✅ Performance improvements are significant\n";
        echo "✅ All tasks complete successfully\n";
    } else {
        echo "⚠️  SOME TESTS FAILED - Concurrency may not be working properly!\n";
        echo "Please check:\n";
        echo "- PHP Fiber extension is installed and enabled\n";
        echo "- FiberAsync library is working correctly\n";
        echo "- No blocking operations preventing concurrency\n";
    }

    return $allTestsPassed;
}

// Run the tests and get the result
$testsSuccessful = testConcurrencyWithSimulation();

// Exit with appropriate code for CI/CD or automated testing
exit($testsSuccessful ? 0 : 1);
