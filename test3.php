<?php

require_once __DIR__ . '/vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;

$http = new HttpHandler();

echo "=== Testing FiberAsync Proxy ===\n";
echo "Make sure to start the proxy first: php fiber_async_proxy.php\n\n";

// Wait a moment for user to start proxy
echo "Press Enter when proxy is running...";
fgets(STDIN);

run(function () use ($http) {
    echo "1. Testing direct request (no proxy)...\n";
    try {
        $response = await($http->request()->retry()->get('https://httpbin.org/ip'));

        $body = $response->getBody();
        echo "   Direct response body: " . substr($body, 0, 100) . "...\n";

        $data = json_decode($body, true);
        if ($data && isset($data['origin'])) {
            echo "   Direct IP: " . $data['origin'] . "\n";
            $directIp = $data['origin'];
        } else {
            echo "   Could not parse IP from response\n";
        }
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
        return;
    }

    echo "\n2. Testing request through FiberAsync proxy...\n";
    try {
        $response = await($http->request()
            ->proxy('127.0.0.1', 8888)
            ->timeout(15)
            ->retry()
            ->get('https://httpbin.org/ip'));

        print_r($response->json());
        echo "   Proxy IP: " . $data['origin'] . "\n";

        if (isset($directIp) && $data['origin'] !== $directIp) {
            echo "   ✓ IP changed - proxy is working!\n";
        } else {
            echo "   ✓ Request successful through proxy\n";
        }
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
    }

    echo "\n3. Testing POST request through proxy...\n";
    try {
        $response = await($http->request()
            ->proxy('127.0.0.1', 8888)
            ->retry()
            ->timeout(15)
            ->post('https://httpbin.org/post', ['test' => 'data', 'proxy' => 'fiberasync']));

        $data = json_decode($response->getBody(), true);
        if (isset($data['json']['test']) && $data['json']['test'] === 'data') {
            echo "   ✓ POST data transmitted correctly through proxy\n";
        } else {
            echo "   ⚠ POST response received but data may be incorrect\n";
        }
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
    }

    echo "\n4. Testing multiple concurrent requests...\n";
    try {
        $urls = [
            'https://httpbin.org/delay/1',
            'https://httpbin.org/uuid',
            'https://httpbin.org/user-agent',
            'https://httpbin.org/headers'
        ];

        $promises = [];
        foreach ($urls as $i => $url) {
            $promises[] = $http->request()
                ->proxy('127.0.0.1', 8888)
                ->retry()
                ->timeout(15)
                ->get($url);
        }

        $responses = await(all($promises));
        echo "   ✓ " . count($responses) . " concurrent requests completed successfully\n";
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
    }

    echo "\n5. Testing HTTPS through CONNECT tunnel...\n";
    try {
        $response = await($http->request()
            ->proxy('127.0.0.1', 8888)
            ->timeout(15)
            ->get('https://httpbin.org/get'));

        if ($response->ok()) {
            echo "   ✓ HTTPS tunneling working\n";
        } else {
            echo "   ⚠ HTTPS request completed with status: " . $response->status() . "\n";
        }
    } catch (Exception $e) {
        echo "   Error: " . $e->getMessage() . "\n";
    }

    echo "\n=== Tests completed ===\n";
});

