<?php
require_once 'vendor/autoload.php';

echo "Testing Async\n";
$startTime = microtime(true);
$startMemory = memory_get_usage();

try {
    // Each function now defines the COMPLETE async task
    $tasks = [
        'result_1' => function () {
            $response = await(fetch('https://httpbin.org/json')); 

            return $response->json(); 
        },
        'result_2' => function () {
            $response = await(fetch('https://httpbin.org/json'));
            return $response->json();
        },
        'result_3' => function () {
            $response = await(fetch('https://httpbin.org/json'));
            return $response->json();
        },
    ];

    $results = run_all($tasks); 

    echo "Success! Got result:\n";
    print_r($results);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$endTime = microtime(true);
$endMemory = memory_get_usage();
$peakMemory = memory_get_peak_usage();

echo "Execution time: " . round($endTime - $startTime, 4) . " seconds\n";
echo "Memory usage: " . round(($endMemory - $startMemory) / 1024, 2) . " KB\n";
echo "Peak memory usage: " . round($peakMemory / 1024, 2) . " KB\n";


echo "Testing Sync Guzzle\n";
$startTimeSync = microtime(true);
$startMemorySync = memory_get_usage();

try {
    $client = new \GuzzleHttp\Client();

    echo "Fetching URL (sync)...\n";
    $response1 = $client->get('https://httpbin.org/json');
    $response2 = $client->get('https://httpbin.org/json');
    $response3 = $client->get('https://httpbin.org/json');

    $resultSync = [
        "result1" => json_decode($response1->getBody(), true),
        "result2" => json_decode($response2->getBody(), true),
        "result3" => json_decode($response3->getBody(), true),
    ];

    echo "Success! Got result (sync):\n";
    print_r($resultSync);
} catch (Throwable $e) {
    echo "Error (sync): " . $e->getMessage() . "\n";
}

$endTimeSync = microtime(true);
$endMemorySync = memory_get_usage();
$peakMemorySync = memory_get_peak_usage();

echo "Execution time (sync): " . round($endTimeSync - $startTimeSync, 4) . " seconds\n";
echo "Memory usage (sync): " . round(($endMemorySync - $startMemorySync) / 1024, 2) . " KB\n";
echo "Peak memory usage (sync): " . round($peakMemorySync / 1024, 2) . " KB\n";
