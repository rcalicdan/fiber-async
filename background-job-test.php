<?php
// backgroundjob-test.php

require __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\BackgroundJob;

echo "=== BackgroundJob Comprehensive Test Suite ===\n\n";

// Configure BackgroundJob for testing
BackgroundJob::configure([
    'enabled' => true,
    'fallback_to_sync' => false,
    'timeout' => 30,
    'max_retries' => 2,
    'retry_delay' => 1,
    'memory_limit' => '256M',
    'log_level' => 'info'
]);

// Test configuration
$testResults = [];
$logFile = __DIR__ . '/backgroundjob_test_log.txt';
$tempDir = sys_get_temp_dir();

// Clear previous test logs
if (file_exists($logFile)) {
    unlink($logFile);
}

// Clear any existing test files
$testFiles = glob($tempDir . '/bg_job_test_*.txt');
foreach ($testFiles as $file) {
    @unlink($file);
}

echo "Starting comprehensive BackgroundJob tests...\n\n";

// =============================================================================
// TEST 1: Basic BackgroundJob System Status
// =============================================================================

echo "TEST 1: BackgroundJob System Status Check\n";
echo "- Checking if BackgroundJob system is properly configured\n";

$status = BackgroundJob::status();
$isEnabled = BackgroundJob::isEnabled();

echo "✓ BackgroundJob enabled: " . ($status['enabled'] ? 'YES' : 'NO') . "\n";
echo "✓ Worker reachable: " . ($status['worker_reachable'] ? 'YES' : 'NO') . "\n";
echo "✓ Overall system ready: " . ($isEnabled ? 'YES' : 'NO') . "\n";

if ($isEnabled) {
    echo "✓ PASS: BackgroundJob system is ready\n";
    $testResults['system_status'] = 'PASS';
} else {
    echo "✗ FAIL: BackgroundJob system is not ready\n";
    $testResults['system_status'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 2: Built-in Self Test
// =============================================================================

echo "TEST 2: BackgroundJob Built-in Self Test\n";
echo "- Running BackgroundJob::test() method\n";

$selfTestResults = BackgroundJob::test();

echo "Basic dispatch test: " . $selfTestResults['basic_dispatch']['status'] . "\n";
echo "Array dispatch test: " . $selfTestResults['array_dispatch']['status'] . "\n";
echo "Worker connection test: " . $selfTestResults['worker_connection']['status'] . "\n";

$passedTests = count(array_filter($selfTestResults, fn($r) => $r['status'] === 'success'));
$totalTests = count($selfTestResults);

if ($passedTests === $totalTests) {
    echo "✓ PASS: All self-tests passed ({$passedTests}/{$totalTests})\n";
    $testResults['self_test'] = 'PASS';
} else {
    echo "✗ FAIL: Some self-tests failed ({$passedTests}/{$totalTests})\n";
    $testResults['self_test'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 3: Basic Closure Dispatch Test
// =============================================================================

echo "TEST 3: Basic Closure Dispatch Test\n";
echo "- Testing BackgroundJob::dispatch() with closure\n";

$closureTestFile = $tempDir . '/bg_job_test_closure.txt';
$startTime = microtime(true);

$jobId = BackgroundJob::dispatch(function() use ($closureTestFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($closureTestFile, "Closure job executed at {$timestamp}\n", FILE_APPEND);
    
    // Simulate some work
    sleep(2);
    
    $endTimestamp = date('Y-m-d H:i:s');
    file_put_contents($closureTestFile, "Closure job completed at {$endTimestamp}\n", FILE_APPEND);
});

$returnTime = microtime(true);
$executionTime = ($returnTime - $startTime) * 1000;

echo "✓ Job dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$executionTime}ms\n";

if ($executionTime < 300) {
    echo "✓ PASS: Closure dispatch is non-blocking\n";
    $testResults['closure_dispatch'] = 'PASS';
} else {
    echo "✗ FAIL: Closure dispatch took too long (blocking behavior)\n";
    $testResults['closure_dispatch'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 4: Array Job Dispatch Test
// =============================================================================

echo "TEST 4: Array Job Dispatch Test\n";
echo "- Testing BackgroundJob::dispatch() with array data\n";

$arrayTestFile = $tempDir . '/bg_job_test_array.txt';
$startTime = microtime(true);

$jobId = BackgroundJob::dispatch([
    'action' => 'process_data',
    'data' => ['user_id' => 123, 'task' => 'send_email'],
    'callback' => function($job) use ($arrayTestFile) {
        $timestamp = date('Y-m-d H:i:s');
        $logData = [
            'timestamp' => $timestamp,
            'action' => $job['action'],
            'data' => $job['data']
        ];
        file_put_contents($arrayTestFile, json_encode($logData) . "\n", FILE_APPEND);
        
        sleep(1);
        
        $endTimestamp = date('Y-m-d H:i:s');
        file_put_contents($arrayTestFile, "Array job completed at {$endTimestamp}\n", FILE_APPEND);
    }
]);

$returnTime = microtime(true);
$executionTime = ($returnTime - $startTime) * 1000;

echo "✓ Array job dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$executionTime}ms\n";

if ($executionTime < 300) {
    echo "✓ PASS: Array job dispatch is non-blocking\n";
    $testResults['array_dispatch'] = 'PASS';
} else {
    echo "✗ FAIL: Array job dispatch took too long\n";
    $testResults['array_dispatch'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 5: Delayed Job Test
// =============================================================================

echo "TEST 5: Delayed Job Test\n";
echo "- Testing BackgroundJob::delay() functionality\n";

$delayTestFile = $tempDir . '/bg_job_test_delay.txt';
$startTime = microtime(true);

$jobId = BackgroundJob::delay(3)->dispatch(function() use ($delayTestFile, $startTime) {
    $actualDelay = microtime(true) - $startTime;
    $timestamp = date('Y-m-d H:i:s');
    
    file_put_contents($delayTestFile, "Delayed job executed at {$timestamp}\n", FILE_APPEND);
    file_put_contents($delayTestFile, "Actual delay: {$actualDelay} seconds\n", FILE_APPEND);
});

$returnTime = microtime(true);
$executionTime = ($returnTime - $startTime) * 1000;

echo "✓ Delayed job dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$executionTime}ms (should be immediate)\n";

if ($executionTime < 300) {
    echo "✓ PASS: Delayed job dispatch is non-blocking\n";
    $testResults['delayed_job'] = 'PASS';
} else {
    echo "✗ FAIL: Delayed job dispatch is blocking\n";
    $testResults['delayed_job'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 6: Multiple Concurrent Jobs Test
// =============================================================================

echo "TEST 6: Multiple Concurrent Jobs Test\n";
echo "- Dispatching 5 jobs simultaneously with different durations\n";

$concurrentTestFile = $tempDir . '/bg_job_test_concurrent.txt';
$concurrentStartTime = microtime(true);

$jobIds = [];
for ($i = 1; $i <= 5; $i++) {
    $jobId = BackgroundJob::tags("concurrent", "test_{$i}")
        ->priority($i)
        ->dispatch(function($taskNumber) use ($concurrentTestFile) {
            $startTime = microtime(true);
            $timestamp = date('Y-m-d H:i:s.u');
            
            file_put_contents($concurrentTestFile, "Task {$taskNumber} started at {$timestamp}\n", FILE_APPEND);
            
            // Each task has different duration
            sleep($taskNumber);
            
            $endTime = microtime(true);
            $duration = $endTime - $startTime;
            $endTimestamp = date('Y-m-d H:i:s.u');
            
            file_put_contents($concurrentTestFile, "Task {$taskNumber} completed at {$endTimestamp} (duration: {$duration}s)\n", FILE_APPEND);
        }, $i);
    
    $jobIds[] = $jobId;
}

$concurrentReturnTime = microtime(true);
$concurrentExecutionTime = ($concurrentReturnTime - $concurrentStartTime) * 1000;

echo "✓ All 5 jobs dispatched in {$concurrentExecutionTime}ms\n";
echo "✓ Job IDs: " . implode(', ', array_map(fn($id) => substr($id, -8), $jobIds)) . "\n";

if ($concurrentExecutionTime < 500) {
    echo "✓ PASS: Multiple jobs dispatched concurrently\n";
    $testResults['concurrent_jobs'] = 'PASS';
} else {
    echo "✗ FAIL: Multiple job dispatch took too long\n";
    $testResults['concurrent_jobs'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 7: HTTP Request Job Test
// =============================================================================

echo "TEST 7: HTTP Request Job Test\n";
echo "- Testing BackgroundJob::dispatchHttpRequest()\n";

$httpTestFile = $tempDir . '/bg_job_test_http.txt';
$httpStartTime = microtime(true);

$jobId = BackgroundJob::dispatchHttpRequest('https://httpbin.org/delay/2', [
    'method' => 'GET',
    'timeout' => 30,
    'headers' => ['User-Agent: BackgroundJob-Test/1.0']
]);

$httpReturnTime = microtime(true);
$httpExecutionTime = ($httpReturnTime - $httpStartTime) * 1000;

echo "✓ HTTP request job dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$httpExecutionTime}ms\n";

if ($httpExecutionTime < 500) {
    echo "✓ PASS: HTTP request job is non-blocking\n";
    $testResults['http_request'] = 'PASS';
} else {
    echo "✗ FAIL: HTTP request job is blocking\n";
    $testResults['http_request'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 8: Database Operation Job Test
// =============================================================================

echo "TEST 8: Database Operation Job Test\n";
echo "- Testing BackgroundJob::dispatchDatabaseOperation()\n";

$dbStartTime = microtime(true);

$jobId = BackgroundJob::queue('database')
    ->tags('db', 'user_update')
    ->dispatchDatabaseOperation('update_user_stats', [
        'user_id' => 123,
        'stats' => ['login_count' => 1, 'last_login' => time()],
        'table' => 'user_statistics'
    ]);

$dbReturnTime = microtime(true);
$dbExecutionTime = ($dbReturnTime - $dbStartTime) * 1000;

echo "✓ Database operation job dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$dbExecutionTime}ms\n";

if ($dbExecutionTime < 300) {
    echo "✓ PASS: Database operation job is non-blocking\n";
    $testResults['database_operation'] = 'PASS';
} else {
    echo "✗ FAIL: Database operation job is blocking\n";
    $testResults['database_operation'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 9: Job Class Dispatch Test
// =============================================================================

echo "TEST 9: Job Class Dispatch Test\n";
echo "- Testing BackgroundJob::dispatchJob() with mock class\n";

// Create a mock job class
$mockJobClass = '
class MockEmailJob {
    public function handle($data) {
        $timestamp = date("Y-m-d H:i:s");
        $logFile = "' . $tempDir . '/bg_job_test_class.txt";
        
        file_put_contents($logFile, "MockEmailJob executed at {$timestamp}\n", FILE_APPEND);
        file_put_contents($logFile, "Job data: " . json_encode($data) . "\n", FILE_APPEND);
        
        sleep(1);
        
        $endTimestamp = date("Y-m-d H:i:s");
        file_put_contents($logFile, "MockEmailJob completed at {$endTimestamp}\n", FILE_APPEND);
    }
}';

eval($mockJobClass);

$classStartTime = microtime(true);

$jobId = BackgroundJob::dispatchJob('MockEmailJob', [
    'to' => 'test@example.com',
    'subject' => 'Test Email',
    'template' => 'welcome'
]);

$classReturnTime = microtime(true);
$classExecutionTime = ($classReturnTime - $classStartTime) * 1000;

echo "✓ Job class dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$classExecutionTime}ms\n";

if ($classExecutionTime < 300) {
    echo "✓ PASS: Job class dispatch is non-blocking\n";
    $testResults['job_class'] = 'PASS';
} else {
    echo "✗ FAIL: Job class dispatch is blocking\n";
    $testResults['job_class'] = 'FAIL';
}

echo "\n";

// =============================================================================
// TEST 10: Error Handling Test
// =============================================================================

echo "TEST 10: Error Handling Test\n";
echo "- Testing job that throws an exception\n";

$errorTestFile = $tempDir . '/bg_job_test_error.txt';
$errorStartTime = microtime(true);

$jobId = BackgroundJob::dispatch(function() use ($errorTestFile) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($errorTestFile, "Error test job started at {$timestamp}\n", FILE_APPEND);
    
    // This should cause an error in the background
    throw new Exception("Intentional test error");
});

$errorReturnTime = microtime(true);
$errorExecutionTime = ($errorReturnTime - $errorStartTime) * 1000;

echo "✓ Error job dispatched with ID: {$jobId}\n";
echo "✓ Dispatch returned in {$errorExecutionTime}ms (should not block despite error)\n";

if ($errorExecutionTime < 300) {
    echo "✓ PASS: Error handling doesn't block dispatch\n";
    $testResults['error_handling'] = 'PASS';
} else {
    echo "✗ FAIL: Error handling is blocking dispatch\n";
    $testResults['error_handling'] = 'FAIL';
}

echo "\n";

// =============================================================================
// Wait and Monitor Results
// =============================================================================

echo "=== Waiting for background jobs to complete ===\n";
echo "Monitoring test files for 20 seconds...\n\n";

$monitorStart = time();
$monitoredFiles = [
    'closure' => $closureTestFile,
    'array' => $arrayTestFile,
    'delay' => $delayTestFile,
    'concurrent' => $concurrentTestFile,
    'class' => $tempDir . '/bg_job_test_class.txt',
    'error' => $errorTestFile
];

$lastSizes = [];
foreach ($monitoredFiles as $type => $file) {
    $lastSizes[$type] = file_exists($file) ? filesize($file) : 0;
}

while ((time() - $monitorStart) < 20) {
    $hasNewContent = false;
    
    foreach ($monitoredFiles as $type => $file) {
        if (file_exists($file)) {
            $currentSize = filesize($file);
            if ($currentSize > $lastSizes[$type]) {
                $content = file_get_contents($file, false, null, $lastSizes[$type]);
                $lines = explode("\n", trim($content));
                
                foreach ($lines as $line) {
                    if (trim($line)) {
                        echo "LOG [{$type}]: " . trim($line) . "\n";
                        $hasNewContent = true;
                    }
                }
                
                $lastSizes[$type] = $currentSize;
            }
        }
    }
    
    if (!$hasNewContent) {
        echo ".";
    }
    
    sleep(1);
}

echo "\n\n";

// =============================================================================
// File-based Results Verification
// =============================================================================

echo "=== File-based Results Verification ===\n";

$fileTests = [
    'closure_file' => $closureTestFile,
    'array_file' => $arrayTestFile,
    'delay_file' => $delayTestFile,
    'concurrent_file' => $concurrentTestFile,
    'class_file' => $tempDir . '/bg_job_test_class.txt'
];

foreach ($fileTests as $testName => $file) {
    if (file_exists($file) && filesize($file) > 0) {
        echo "✓ {$testName}: File created and has content\n";
        $testResults[$testName] = 'PASS';
    } else {
        echo "✗ {$testName}: File missing or empty\n";
        $testResults[$testName] = 'FAIL';
    }
}

echo "\n";

// =============================================================================
// Final Results and Analysis
// =============================================================================

echo "=== TEST RESULTS SUMMARY ===\n";

$categories = [
    'System Tests' => ['system_status', 'self_test'],
    'Basic Dispatch Tests' => ['closure_dispatch', 'array_dispatch'],
    'Advanced Features' => ['delayed_job', 'concurrent_jobs'],
    'Framework Integration' => ['http_request', 'database_operation', 'job_class'],
    'Error Handling' => ['error_handling'],
    'File Verification' => ['closure_file', 'array_file', 'delay_file', 'concurrent_file', 'class_file']
];

foreach ($categories as $category => $tests) {
    echo "\n{$category}:\n";
    foreach ($tests as $test) {
        if (isset($testResults[$test])) {
            $status = $testResults[$test] === 'PASS' ? '✓' : '✗';
            echo "  {$status} {$test}: {$testResults[$test]}\n";
        }
    }
}

$passCount = count(array_filter($testResults, fn($r) => $r === 'PASS'));
$totalTests = count($testResults);

echo "\nOverall Results: {$passCount}/{$totalTests} tests passed\n";

if ($passCount === $totalTests) {
    echo "🎉 ALL TESTS PASSED - BackgroundJob is working perfectly!\n";
} else if ($passCount >= $totalTests * 0.8) {
    echo "⚠️  Most tests passed - BackgroundJob is mostly functional\n";
} else {
    echo "❌ Many tests failed - BackgroundJob has significant issues\n";
}

// =============================================================================
// Debug Information
// =============================================================================

echo "\n=== DEBUG INFORMATION ===\n";
$debugInfo = BackgroundJob::status();

echo "BackgroundJob Configuration:\n";
echo json_encode($debugInfo['config'], JSON_PRETTY_PRINT) . "\n";

echo "\nBackground Worker Debug:\n";
echo json_encode($debugInfo['background_debug'], JSON_PRETTY_PRINT) . "\n";

// =============================================================================
// Test File Contents
// =============================================================================

echo "\n=== TEST FILE CONTENTS ===\n";

foreach ($monitoredFiles as $type => $file) {
    echo "\n--- {$type} test file ---\n";
    if (file_exists($file)) {
        echo file_get_contents($file);
    } else {
        echo "File not found: {$file}\n";
    }
}

// Cleanup
echo "\n=== CLEANUP ===\n";
$cleanupCount = 0;
foreach ($monitoredFiles as $file) {
    if (file_exists($file)) {
        unlink($file);
        $cleanupCount++;
    }
}
echo "Cleaned up {$cleanupCount} test files\n";

echo "\n=== BackgroundJob Test Complete ===\n";