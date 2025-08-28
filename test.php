<?php
// File: multi_api_test.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Promise;
use Rcalicdan\FiberAsync\ProxyClient\ProxyClient;
use Rcalicdan\FiberAsync\ProxyClient\ResilientClientConfig;

run(function () {
    echo "======================================================================\n";
    echo " Multi-API Resilient Client Test\n";
    echo "======================================================================\n";

    try {
        ProxyClient::configure(
            (new ResilientClientConfig)
                ->withMaxAttempts(5)
                ->withTimeout(60)
                ->withHealthTracking()
                ->withUserAgent('Mozilla/5.0 (compatible; TestBot/1.0)')
        );


        echo "\n--- Step 2: Concurrently testing multiple public APIs... ---\n\n";
        
        $results = await(Promise::all([
            'geolocation' => ProxyClient::get('http://ip-api.com/json'),
            'cat_fact'    => ProxyClient::get('https://catfact.ninja/fact'),
            'crypto_ping' => ProxyClient::get('https://api.coingecko.com/api/v3/ping'),
        ]));

        // --- Step 3: Report the results for each API ---
        echo "\n======================================================================\n";
        echo " Test Results\n";
        echo "======================================================================\n";

        // Geolocation Test Result
        echo "\n--- 1. Geolocation Test (ip-api.com) ---\n";
        $geoResponse = $results['geolocation'];
        if ($geoResponse->ok()) {
            $data = $geoResponse->json();
            echo "\033[32m✅ SUCCESS\033[0m\n";
            echo "   - Proxy IP:      " . ($data['query'] ?? 'N/A') . "\n";
            echo "   - Location:      " . ($data['city'] ?? 'N/A') . ", " . ($data['country'] ?? 'N/A') . "\n";
            echo "   - ISP:           " . ($data['isp'] ?? 'N/A') . "\n";
        } else {
            echo "\033[31m❌ FAILED\033[0m with status: " . $geoResponse->status() . "\n";
        }

        // Cat Fact Test Result
        echo "\n--- 2. Cat Fact Test (catfact.ninja) ---\n";
        $catResponse = $results['cat_fact'];
        if ($catResponse->ok()) {
            $data = $catResponse->json();
            echo "\033[32m✅ SUCCESS\033[0m\n";
            echo "   - Fact:          " . ($data['fact'] ?? 'N/A') . "\n";
        } else {
            echo "\033[31m❌ FAILED\033[0m with status: " . $catResponse->status() . "\n";
        }

        // Crypto Ping Test Result
        echo "\n--- 3. Crypto API Ping Test (coingecko.com) ---\n";
        $pingResponse = $results['crypto_ping'];
        if ($pingResponse->ok()) {
            $data = $pingResponse->json();
            echo "\033[32m✅ SUCCESS\033[0m\n";
            echo "   - Response:      " . ($data['gecko_says'] ?? 'N/A') . "\n";
        } else {
            echo "\033[31m❌ FAILED\033[0m with status: " . $pingResponse->status() . "\n";
        }

        echo "\n======================================================================\n";
        echo "✅ All concurrent tests completed.\n";


    } catch (\Throwable $e) {
        echo "\n\033[31m--- TEST FAILED ---\033[0m\n";
        echo "The resilient client failed after exhausting all available proxies.\n";
        echo "Final Error: " . $e->getMessage() . "\n";
    }
});