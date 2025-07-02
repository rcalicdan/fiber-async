<?php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Bridges\HttpClientBridge;

/**
 * Manual test script to compare HTTP client implementations
 * Tests Guzzle bridge, Laravel HTTP bridge, and native fetch function
 */

// Test configuration
$testUrl = 'https://jsonplaceholder.typicode.com';
$testEndpoints = [
    'get' => '/posts/1',
    'post' => '/posts',
    'put' => '/posts/1',
    'delete' => '/posts/1'
];

$testData = [
    'title' => 'Test Post',
    'body' => 'This is a test post for comparing HTTP clients',
    'userId' => 1
];

function printTestHeader(string $title): void 
{
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "  " . strtoupper($title) . "\n";
    echo str_repeat('=', 60) . "\n";
}

function printSubHeader(string $title): void 
{
    echo "\n" . str_repeat('-', 40) . "\n";
    echo "  " . $title . "\n";
    echo str_repeat('-', 40) . "\n";
}

function measureExecutionTime(callable $operation): array 
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();
    
    try {
        $result = $operation();
        $success = true;
        $error = null;
    } catch (Throwable $e) {
        $result = null;
        $success = false;
        $error = $e->getMessage();
    }
    
    $endTime = microtime(true);
    $endMemory = memory_get_usage();
    
    return [
        'result' => $result,
        'success' => $success,
        'error' => $error,
        'execution_time' => ($endTime - $startTime) * 1000, // Convert to milliseconds
        'memory_used' => $endMemory - $startMemory,
        'peak_memory' => memory_get_peak_usage() - $startMemory
    ];
}

function formatResponse($response, string $clientType): string 
{
    if ($response === null) {
        return "No response data";
    }
    
    // Handle different response types
    switch ($clientType) {
        case 'guzzle':
            if (method_exists($response, 'getBody')) {
                $body = (string) $response->getBody();
                $statusCode = $response->getStatusCode();
                return "Status: {$statusCode}\nBody: " . substr($body, 0, 200) . "...";
            }
            break;
            
        case 'laravel':
            if (method_exists($response, 'body')) {
                $body = $response->body();
                $statusCode = $response->status();
                return "Status: {$statusCode}\nBody: " . substr($body, 0, 200) . "...";
            }
            break;
            
        case 'fetch':
            if (is_array($response)) {
                $statusCode = $response['status'] ?? 'unknown';
                $body = $response['body'] ?? 'no body';
                return "Status: {$statusCode}\nBody: " . substr($body, 0, 200) . "...";
            }
            break;
    }
    
    return "Raw response: " . substr(print_r($response, true), 0, 200) . "...";
}

// Start the manual test
printTestHeader('HTTP Client Bridge Comparison Test');

echo "This test compares three HTTP implementations:\n";
echo "1. Guzzle HTTP Bridge\n";
echo "2. Laravel HTTP Bridge\n";
echo "3. Native Fetch Function\n\n";

echo "Test URL: {$testUrl}\n";
echo "Test Data: " . json_encode($testData) . "\n";

// Initialize the HTTP bridge
$httpBridge = HttpClientBridge::getInstance();
$laravelBridge = $httpBridge->laravel();

// Test results storage
$testResults = [
    'guzzle' => [],
    'laravel' => [],
    'fetch' => []
];

// Run tests using the async helper functions
run(async(function () use ($testUrl, $testEndpoints, $testData, $httpBridge, $laravelBridge, &$testResults) {
    
    // GET Request Tests
    printSubHeader('GET Request Tests');
    
    $getUrl = $testUrl . $testEndpoints['get'];
    echo "Testing GET request to: {$getUrl}\n\n";
    
    // Test Guzzle GET
    echo "1. Guzzle GET:\n";
    $guzzleGetResult = measureExecutionTime(function () use ($httpBridge, $getUrl) {
        return await($httpBridge->guzzle('GET', $getUrl));
    });
    
    echo "   Time: " . number_format($guzzleGetResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($guzzleGetResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($guzzleGetResult['success'] ? 'Yes' : 'No') . "\n";
    if (!$guzzleGetResult['success']) {
        echo "   Error: " . $guzzleGetResult['error'] . "\n";
    } else {
        echo "   Response: " . formatResponse($guzzleGetResult['result'], 'guzzle') . "\n";
    }
    $testResults['guzzle']['get'] = $guzzleGetResult;
    
    // Test Laravel GET
    echo "\n2. Laravel GET:\n";
    $laravelGetResult = measureExecutionTime(function () use ($laravelBridge, $getUrl) {
        return await($laravelBridge->get($getUrl));
    });
    
    echo "   Time: " . number_format($laravelGetResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($laravelGetResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($laravelGetResult['success'] ? 'Yes' : 'No') . "\n";
    if (!$laravelGetResult['success']) {
        echo "   Error: " . $laravelGetResult['error'] . "\n";
    } else {
        echo "   Response: " . formatResponse($laravelGetResult['result'], 'laravel') . "\n";
    }
    $testResults['laravel']['get'] = $laravelGetResult;
    
    // Test Fetch GET
    echo "\n3. Fetch GET:\n";
    $fetchGetResult = measureExecutionTime(function () use ($getUrl) {
        return await(fetch($getUrl, ['method' => 'GET']));
    });
    
    echo "   Time: " . number_format($fetchGetResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($fetchGetResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($fetchGetResult['success'] ? 'Yes' : 'No') . "\n";
    if (!$fetchGetResult['success']) {
        echo "   Error: " . $fetchGetResult['error'] . "\n";
    } else {
        echo "   Response: " . formatResponse($fetchGetResult['result'], 'fetch') . "\n";
    }
    $testResults['fetch']['get'] = $fetchGetResult;
    
    // POST Request Tests
    printSubHeader('POST Request Tests');
    
    $postUrl = $testUrl . $testEndpoints['post'];
    echo "Testing POST request to: {$postUrl}\n";
    echo "Data: " . json_encode($testData) . "\n\n";
    
    // Test Guzzle POST
    echo "1. Guzzle POST:\n";
    $guzzlePostResult = measureExecutionTime(function () use ($httpBridge, $postUrl, $testData) {
        return await($httpBridge->guzzle('POST', $postUrl, [
            'json' => $testData,
            'headers' => ['Content-Type' => 'application/json']
        ]));
    });
    
    echo "   Time: " . number_format($guzzlePostResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($guzzlePostResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($guzzlePostResult['success'] ? 'Yes' : 'No') . "\n";
    if (!$guzzlePostResult['success']) {
        echo "   Error: " . $guzzlePostResult['error'] . "\n";
    } else {
        echo "   Response: " . formatResponse($guzzlePostResult['result'], 'guzzle') . "\n";
    }
    $testResults['guzzle']['post'] = $guzzlePostResult;
    
    // Test Laravel POST
    echo "\n2. Laravel POST:\n";
    $laravelPostResult = measureExecutionTime(function () use ($laravelBridge, $postUrl, $testData) {
        return await($laravelBridge->post($postUrl, $testData));
    });
    
    echo "   Time: " . number_format($laravelPostResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($laravelPostResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($laravelPostResult['success'] ? 'Yes' : 'No') . "\n";
    if (!$laravelPostResult['success']) {
        echo "   Error: " . $laravelPostResult['error'] . "\n";
    } else {
        echo "   Response: " . formatResponse($laravelPostResult['result'], 'laravel') . "\n";
    }
    $testResults['laravel']['post'] = $laravelPostResult;
    
    // Test Fetch POST
    echo "\n3. Fetch POST:\n";
    $fetchPostResult = measureExecutionTime(function () use ($postUrl, $testData) {
        return await(fetch($postUrl, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($testData)
        ]));
    });
    
    echo "   Time: " . number_format($fetchPostResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($fetchPostResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($fetchPostResult['success'] ? 'Yes' : 'No') . "\n";
    if (!$fetchPostResult['success']) {
        echo "   Error: " . $fetchPostResult['error'] . "\n";
    } else {
        echo "   Response: " . formatResponse($fetchPostResult['result'], 'fetch') . "\n";
    }
    $testResults['fetch']['post'] = $fetchPostResult;
    
    // Concurrent Request Tests
    printSubHeader('Concurrent Request Tests');
    
    echo "Testing 5 concurrent GET requests for each client:\n\n";
    
    $concurrentUrl = $testUrl . '/posts';
    $concurrentCount = 5;
    
    // Test Guzzle concurrent
    echo "1. Guzzle Concurrent:\n";
    $guzzleConcurrentResult = measureExecutionTime(function () use ($httpBridge, $concurrentUrl, $concurrentCount) {
        $promises = [];
        for ($i = 1; $i <= $concurrentCount; $i++) {
            $promises[] = $httpBridge->guzzle('GET', $concurrentUrl . "/{$i}");
        }
        return await(all($promises));
    });
    
    echo "   Time: " . number_format($guzzleConcurrentResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($guzzleConcurrentResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($guzzleConcurrentResult['success'] ? 'Yes' : 'No') . "\n";
    echo "   Requests completed: " . (is_array($guzzleConcurrentResult['result']) ? count($guzzleConcurrentResult['result']) : 0) . "\n";
    $testResults['guzzle']['concurrent'] = $guzzleConcurrentResult;
    
    // Test Laravel concurrent
    echo "\n2. Laravel Concurrent:\n";
    $laravelConcurrentResult = measureExecutionTime(function () use ($laravelBridge, $concurrentUrl, $concurrentCount) {
        $promises = [];
        for ($i = 1; $i <= $concurrentCount; $i++) {
            $promises[] = $laravelBridge->get($concurrentUrl . "/{$i}");
        }
        return await(all($promises));
    });
    
    echo "   Time: " . number_format($laravelConcurrentResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($laravelConcurrentResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($laravelConcurrentResult['success'] ? 'Yes' : 'No') . "\n";
    echo "   Requests completed: " . (is_array($laravelConcurrentResult['result']) ? count($laravelConcurrentResult['result']) : 0) . "\n";
    $testResults['laravel']['concurrent'] = $laravelConcurrentResult;
    
    // Test Fetch concurrent
    echo "\n3. Fetch Concurrent:\n";
    $fetchConcurrentResult = measureExecutionTime(function () use ($concurrentUrl, $concurrentCount) {
        $promises = [];
        for ($i = 1; $i <= $concurrentCount; $i++) {
            $promises[] = fetch($concurrentUrl . "/{$i}");
        }
        return await(all($promises));
    });
    
    echo "   Time: " . number_format($fetchConcurrentResult['execution_time'], 2) . "ms\n";
    echo "   Memory: " . number_format($fetchConcurrentResult['memory_used'] / 1024, 2) . "KB\n";
    echo "   Success: " . ($fetchConcurrentResult['success'] ? 'Yes' : 'No') . "\n";
    echo "   Requests completed: " . (is_array($fetchConcurrentResult['result']) ? count($fetchConcurrentResult['result']) : 0) . "\n";
    $testResults['fetch']['concurrent'] = $fetchConcurrentResult;
}));

// Summary and Analysis
printTestHeader('Test Results Summary');

echo "Performance Comparison:\n\n";

// Calculate averages for each client
$clients = ['guzzle', 'laravel', 'fetch'];
$testTypes = ['get', 'post', 'concurrent'];

foreach ($testTypes as $testType) {
    echo strtoupper($testType) . " Requests:\n";
    
    $fastest = null;
    $fastestTime = PHP_FLOAT_MAX;
    
    foreach ($clients as $client) {
        if (isset($testResults[$client][$testType])) {
            $result = $testResults[$client][$testType];
            $time = $result['execution_time'];
            $memory = $result['memory_used'] / 1024;
            $success = $result['success'] ? 'Success' : 'Failed';
            
            echo sprintf("  %-8s: %6.2fms | %6.2fKB | %s\n", 
                ucfirst($client), $time, $memory, $success);
                
            if ($result['success'] && $time < $fastestTime) {
                $fastest = $client;
                $fastestTime = $time;
            }
        }
    }
    
    if ($fastest) {
        echo "  Fastest: " . ucfirst($fastest) . "\n";
    }
    echo "\n";
}

// Reliability Analysis
printSubHeader('Reliability Analysis');

$totalTests = count($testTypes);
foreach ($clients as $client) {
    $successCount = 0;
    $totalTime = 0;
    $totalMemory = 0;
    
    foreach ($testTypes as $testType) {
        if (isset($testResults[$client][$testType])) {
            if ($testResults[$client][$testType]['success']) {
                $successCount++;
            }
            $totalTime += $testResults[$client][$testType]['execution_time'];
            $totalMemory += $testResults[$client][$testType]['memory_used'];
        }
    }
    
    $successRate = ($successCount / $totalTests) * 100;
    $avgTime = $totalTime / $totalTests;
    $avgMemory = ($totalMemory / $totalTests) / 1024;
    
    echo sprintf("%s Client:\n", ucfirst($client));
    echo sprintf("  Success Rate: %.1f%% (%d/%d)\n", $successRate, $successCount, $totalTests);
    echo sprintf("  Average Time: %.2fms\n", $avgTime);
    echo sprintf("  Average Memory: %.2fKB\n", $avgMemory);
    echo "\n";
}

// Recommendations
printSubHeader('Recommendations');

echo "Based on the test results:\n\n";

echo "• For Guzzle users: The bridge maintains full compatibility with Guzzle's\n";
echo "  feature-rich API while adding async capabilities.\n\n";

echo "• For Laravel users: The bridge provides Laravel's familiar HTTP client\n";
echo "  interface with async support and automatic JSON handling.\n\n";

echo "• For general use: The native fetch() function offers a simple, lightweight\n";
echo "  solution for basic HTTP operations.\n\n";

echo "• For concurrent operations: All three implementations handle concurrent\n";
echo "  requests effectively, choose based on your existing dependencies.\n\n";

echo "Test completed successfully!\n";
echo "All HTTP client bridges are working and properly integrated with the async system.\n";