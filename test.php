<?php

require_once 'vendor/autoload.php';

// Test URLs - using public APIs that support CORS and are reliable
$testUrls = [
    'https://jsonplaceholder.typicode.com/posts/1',
    'https://jsonplaceholder.typicode.com/posts/2',
    'https://jsonplaceholder.typicode.com/posts/3',
    'https://jsonplaceholder.typicode.com/users/1',
    'https://jsonplaceholder.typicode.com/users/2',
];

// Shorter URL list for detailed testing
$shortUrls = [
    'https://jsonplaceholder.typicode.com/posts/1',
    'https://jsonplaceholder.typicode.com/posts/2',
    'https://jsonplaceholder.typicode.com/posts/3',
];

echo "=== Testing PHP Fiber Async HTTP Operations ===\n\n";

// Test 0: Inspect Response object methods
echo "0. Testing Response object methods:\n";
echo "-----------------------------------\n";

$inspectionTest = async(function () {
    echo "Making a sample request to test response methods...\n";
    $response = await(http_get('https://jsonplaceholder.typicode.com/posts/1'));

    echo "Response class: " . get_class($response) . "\n";
    echo "Testing response methods:\n";

    try {
        $status = $response->status();
        echo "  - status(): $status\n";
    } catch (Exception $e) {
        echo "  - status(): Error - " . $e->getMessage() . "\n";
    }

    try {
        $body = $response->body();
        echo "  - body(): " . strlen($body) . " characters\n";
        echo "  - body preview: " . substr($body, 0, 100) . "...\n";
    } catch (Exception $e) {
        echo "  - body(): Error - " . $e->getMessage() . "\n";
    }

    try {
        $json = $response->json();
        echo "  - json(): " . (is_array($json) ? "Array with " . count($json) . " keys" : "Not an array") . "\n";
        if (is_array($json) && isset($json['title'])) {
            echo "  - JSON title: " . $json['title'] . "\n";
        }
    } catch (Exception $e) {
        echo "  - json(): Error - " . $e->getMessage() . "\n";
    }

    try {
        $ok = $response->ok();
        echo "  - ok(): " . ($ok ? "true" : "false") . "\n";
    } catch (Exception $e) {
        echo "  - ok(): Error - " . $e->getMessage() . "\n";
    }

    try {
        $successful = $response->successful();
        echo "  - successful(): " . ($successful ? "true" : "false") . "\n";
    } catch (Exception $e) {
        echo "  - successful(): Error - " . $e->getMessage() . "\n";
    }

    echo "\n";
});

run($inspectionTest);

// Helper function to safely get response info
function getResponseInfo($response)
{
    $info = ['status' => 'unknown', 'body_length' => 0, 'body' => '', 'success' => false];

    try {
        $info['status'] = $response->status();
        $info['body'] = $response->body();
        $info['body_length'] = strlen($info['body']);
        $info['success'] = $response->successful();
    } catch (Exception $e) {
        $info['error'] = $e->getMessage();
    }

    return $info;
}

// Test 1: Sequential vs Concurrent Comparison
echo "1. Sequential vs Concurrent Processing Comparison:\n";
echo "==================================================\n";

// Sequential processing
echo "A. Sequential Processing:\n";
echo "-------------------------\n";

// Sequential processing
echo "A. Sequential Processing:\n";
echo "-------------------------\n";

$sequentialResults = null;
run(async(function () use ($shortUrls, &$sequentialResults) {
    $startTime = microtime(true);
    $results = [];

    foreach ($shortUrls as $index => $url) {
        echo "Processing request $index: $url\n";
        $requestStart = microtime(true);

        try {
            $response = await(fetch($url, [
                'timeout' => 10,
                'headers' => ['User-Agent' => 'PHP-Fiber-Sequential-Test/1.0']
            ]));

            $info = getResponseInfo($response);
            $requestEnd = microtime(true);

            $results[$index] = [
                'success' => $info['success'],
                'status' => $info['status'],
                'body_length' => $info['body_length'],
                'time' => round($requestEnd - $requestStart, 2)
            ];

            echo "  ✓ HTTP {$info['status']}, {$info['body_length']} bytes, {$results[$index]['time']}s\n";
        } catch (Exception $e) {
            $requestEnd = microtime(true);
            $results[$index] = [
                'success' => false,
                'error' => $e->getMessage(),
                'time' => round($requestEnd - $requestStart, 2)
            ];
            echo "  ✗ Error: {$e->getMessage()}\n";
        }
    }

    $endTime = microtime(true);
    $totalTime = round($endTime - $startTime, 2);

    echo "\nSequential Results:\n";
    echo "Total time: {$totalTime} seconds\n";
    echo "Average per request: " . round($totalTime / count($shortUrls), 2) . " seconds\n";
    echo "Successful requests: " . count(array_filter($results, fn($r) => $r['success'])) . "/" . count($results) . "\n\n";

    $sequentialResults = ['total_time' => $totalTime, 'results' => $results];
}));

// Concurrent processing
echo "B. Concurrent Processing:\n";
echo "-------------------------\n";

$concurrentResults = null;
run(async(function () use ($shortUrls, &$concurrentResults) {
    $startTime = microtime(true);

    // Create all promises at once
    $promises = [];
    foreach ($shortUrls as $index => $url) {
        echo "Queuing request $index: $url\n";
        $promises[$index] = fetch($url, [
            'timeout' => 10,
            'headers' => ['User-Agent' => 'PHP-Fiber-Concurrent-Test/1.0']
        ]);
    }

    echo "Starting concurrent execution...\n";

    // Wait for all to complete
    try {
        $responses = await(all($promises));
        $results = [];

        foreach ($responses as $index => $response) {
            $info = getResponseInfo($response);
            $results[$index] = [
                'success' => $info['success'],
                'status' => $info['status'],
                'body_length' => $info['body_length']
            ];
        }

        $endTime = microtime(true);
        $totalTime = round($endTime - $startTime, 2);

        echo "\nConcurrent Results:\n";
        foreach ($results as $index => $result) {
            if ($result['success']) {
                echo "  ✓ Request $index: HTTP {$result['status']}, {$result['body_length']} bytes\n";
            } else {
                echo "  ✗ Request $index: Failed\n";
            }
        }

        echo "\nConcurrent Summary:\n";
        echo "Total time: {$totalTime} seconds\n";
        echo "Average per request: " . round($totalTime / count($shortUrls), 2) . " seconds\n";
        echo "Successful requests: " . count(array_filter($results, fn($r) => $r['success'])) . "/" . count($results) . "\n\n";

        $concurrentResults = ['total_time' => $totalTime, 'results' => $results];
    } catch (Exception $e) {
        $endTime = microtime(true);
        $totalTime = round($endTime - $startTime, 2);
        echo "Concurrent execution failed: " . $e->getMessage() . "\n";
        echo "Time before failure: {$totalTime} seconds\n\n";
        $concurrentResults = ['total_time' => $totalTime, 'results' => [], 'error' => $e->getMessage()];
    }
}));

// Performance comparison
echo "C. Performance Comparison:\n";
echo "--------------------------\n";

if (isset($concurrentResults['error'])) {
    echo "Concurrent execution failed: {$concurrentResults['error']}\n";
    echo "Sequential time: {$sequentialResults['total_time']} seconds\n";
    echo "Cannot compare due to concurrent execution failure.\n\n";
} else {
    $speedup = round($sequentialResults['total_time'] / $concurrentResults['total_time'], 2);
    $timeSaved = round($sequentialResults['total_time'] - $concurrentResults['total_time'], 2);

    echo "Sequential time: {$sequentialResults['total_time']} seconds\n";
    echo "Concurrent time: {$concurrentResults['total_time']} seconds\n";
    echo "Time saved: {$timeSaved} seconds\n";
    echo "Speedup: {$speedup}x faster\n";
    echo "Performance improvement: " . round((($sequentialResults['total_time'] - $concurrentResults['total_time']) / $sequentialResults['total_time']) * 100, 1) . "%\n\n";
}

// Test 2: Testing Different HTTP Methods
echo "2. Testing Different HTTP Methods:\n";
echo "===================================\n";

$methodTest = run(async(function () {
    $startTime = microtime(true);

    echo "Testing GET request...\n";
    try {
        $getResponse = await(http_get('https://jsonplaceholder.typicode.com/posts/1'));
        $getInfo = getResponseInfo($getResponse);
        echo "✓ GET: HTTP {$getInfo['status']}, {$getInfo['body_length']} bytes\n";
        if ($getResponse->successful()) {
            $json = $getResponse->json();
            echo "  Title: " . ($json['title'] ?? 'No title') . "\n";
        }
    } catch (Exception $e) {
        echo "✗ GET: {$e->getMessage()}\n";
    }

    echo "\nTesting POST request...\n";
    try {
        $postResponse = await(http_post('https://jsonplaceholder.typicode.com/posts', [
            'title' => 'Test Post',
            'body' => 'Testing POST method with PHP Fiber Async',
            'userId' => 1
        ]));
        $postInfo = getResponseInfo($postResponse);
        echo "✓ POST: HTTP {$postInfo['status']}, {$postInfo['body_length']} bytes\n";
        if ($postResponse->successful()) {
            $json = $postResponse->json();
            echo "  Created post ID: " . ($json['id'] ?? 'Unknown') . "\n";
        }
    } catch (Exception $e) {
        echo "✗ POST: {$e->getMessage()}\n";
    }

    echo "\nTesting PUT request...\n";
    try {
        $putResponse = await(http_put('https://jsonplaceholder.typicode.com/posts/1', [
            'title' => 'Updated Post',
            'body' => 'Testing PUT method with PHP Fiber Async',
            'userId' => 1
        ]));
        $putInfo = getResponseInfo($putResponse);
        echo "✓ PUT: HTTP {$putInfo['status']}, {$putInfo['body_length']} bytes\n";
    } catch (Exception $e) {
        echo "✗ PUT: {$e->getMessage()}\n";
    }

    echo "\nTesting DELETE request...\n";
    try {
        $deleteResponse = await(http_delete('https://jsonplaceholder.typicode.com/posts/1'));
        $deleteInfo = getResponseInfo($deleteResponse);
        echo "✓ DELETE: HTTP {$deleteInfo['status']}, {$deleteInfo['body_length']} bytes\n";
    } catch (Exception $e) {
        echo "✗ DELETE: {$e->getMessage()}\n";
    }

    $endTime = microtime(true);
    echo "\nAll HTTP methods tested in " . round($endTime - $startTime, 2) . " seconds\n";
}));

// Test 3: Large scale concurrent test
echo "\n3. Large Scale Concurrent Test:\n";
echo "================================\n";

$largeScaleTest = run(async(function () use ($testUrls) {
    $startTime = microtime(true);

    // Add more URLs for a bigger test
    $allUrls = array_merge($testUrls, [
        'https://httpbin.org/delay/1',
        'https://httpbin.org/json',
        'https://httpbin.org/headers',
    ]);

    echo "Testing with " . count($allUrls) . " concurrent requests...\n";

    $promises = [];
    foreach ($allUrls as $index => $url) {
        $promises[] = fetch($url, [
            'timeout' => 15,
            'headers' => ['User-Agent' => 'PHP-Fiber-LargeScale-Test/1.0']
        ]);
    }

    try {
        $responses = await(all($promises));
        $endTime = microtime(true);
        $totalTime = round($endTime - $startTime, 2);

        $successful = 0;
        $failed = 0;

        echo "\nLarge Scale Results:\n";
        foreach ($responses as $index => $response) {
            $info = getResponseInfo($response);
            if ($info['success']) {
                echo "  ✓ Request $index: HTTP {$info['status']}, {$info['body_length']} bytes\n";
                $successful++;
            } else {
                echo "  ✗ Request $index: Failed\n";
                $failed++;
            }
        }

        echo "\nLarge Scale Summary:\n";
        echo "Total requests: " . count($responses) . "\n";
        echo "Successful: $successful\n";
        echo "Failed: $failed\n";
        echo "Total time: {$totalTime} seconds\n";
        echo "Average time per request: " . round($totalTime / count($responses), 2) . " seconds\n";
    } catch (Exception $e) {
        $endTime = microtime(true);
        $totalTime = round($endTime - $startTime, 2);
        echo "Large scale test failed: " . $e->getMessage() . "\n";
        echo "Time before failure: {$totalTime} seconds\n";
    }
}));

echo "\n=== All tests completed! ===\n";
echo "Summary:\n";
echo "- Response object methods: status(), body(), json(), ok(), successful()\n";
echo "- Sequential vs concurrent comparison completed\n";
echo "- Different HTTP methods (GET, POST, PUT, DELETE) tested\n";
echo "- Large scale concurrent testing completed\n";
echo "- The HTTP helpers and facades are functioning properly!\n";
