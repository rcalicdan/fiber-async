<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Api\Task;

$http = new HttpHandler();

$proxies = [
    ['host' => '57.129.81.201', 'port' => 8080],
    ['host' => '66.36.234.130', 'port' => 1339],
    ['host' => '64.92.82.61', 'port' => 8081],
    ['host' => '51.79.99.237', 'port' => 4502],
    ['host' => '154.62.226.126', 'port' => 8888],
];

echo "=== Proxy IP Change Test ===\n\n";

Task::run(function () use ($http, $proxies) {
    // Step 1: Get your real IP (no proxy)
    echo "Getting your real IP (no proxy)...\n";
    $realResponse = await($http->request()
        ->timeout(10)
        ->get('https://httpbin.org/ip'));

    $realData = json_decode($realResponse->getBody(), true);
    $realIp = $realData['origin'] ?? 'Unknown';
    echo "   Your real IP: {$realIp}\n\n";

    // Step 2: Test each proxy against your real IP
    foreach ($proxies as $i => $proxy) {
        echo "Testing Proxy #" . ($i + 1) . " ({$proxy['host']}:{$proxy['port']})...\n";
        try {
            $response = await($http->request()
                ->proxy($proxy['host'], $proxy['port'])
                ->timeout(10)
                ->get('https://httpbin.org/ip'));

            $data = json_decode($response->getBody(), true);
            $proxyIp = $data['origin'] ?? null;

            if ($proxyIp) {
                $changed = $proxyIp !== $realIp ? "✓ Changed" : "⚠ Same as real IP!";
                echo "   Detected IP: {$proxyIp}  -> {$changed}\n";
            } else {
                echo "   ⚠ Could not detect IP\n";
            }
        } catch (Exception $e) {
            echo "   ⚠ Proxy failed: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
});
