<?php

use GuzzleHttp\Handler\Proxy;
use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\ProxyClient\ProxyClient;
use Rcalicdan\FiberAsync\ProxyClient\ResilientClientConfig;

require_once 'vendor/autoload.php';

run(function () {
    echo "======================================================================\n";
    echo " Auto-Resilient Proxy Client Verification Test\n";
    echo "======================================================================\n";
    try {
        // --- Step 1: Establish the Baseline ---
        echo "\n--- Step 1: Checking Original IP (Direct Connection) ---\n";
        $directResponse = await(Http::get('https://httpbin.org/get'));
        $directData = $directResponse->json();
        $originalIp = $directData['origin'] ?? 'unknown';

        if ($originalIp === 'unknown') {
            throw new \RuntimeException("Could not determine original IP from httpbin.org.");
        }
        echo "✅ Original IP Address: {$originalIp}\n";

        ProxyClient::configure(
            (new ResilientClientConfig)
                ->withMaxAttempts(5)
                ->withTimeout(60)
                ->withUserAgent('Mozilla/5.0 (compatible; TestBot/1.0)')
        );
    
        echo "\n--- Step 2: Making a Resilient Request via the Proxy Pool ---\n";
        $proxiedResponse = await(ProxyClient::get('https://httpbin.org/get'));
        $proxiedData = $proxiedResponse->json();
        $proxiedIp = $proxiedData['origin'] ?? 'unknown';

        if ($proxiedIp === 'unknown') {
            throw new \RuntimeException("Could not determine proxied IP from httpbin.org.");
        }

        // --- Step 3: Analyze and Verify the Results ---
        echo "\n--- Step 3: Analyzing the Results ---\n";
        echo "Original IP:      {$originalIp}\n";
        echo "IP via Proxy:     {$proxiedIp}\n\n";

        if ($proxiedIp !== $originalIp) {
            echo "\033[32m✅ SUCCESS: The proxy successfully masked the original IP address.\033[0m\n";
        } else {
            echo "\033[33m⚠️  WARNING: The request succeeded, but the proxy is transparent.\n";
            echo "It revealed the original IP address instead of hiding it.\033[0m\n";
        }

        // Bonus Check: Look for headers that would expose a non-elite proxy.
        $headers = $proxiedData['headers'] ?? [];
        $isElite = true;
        $revealingHeaders = [];
        foreach ($headers as $headerName => $headerValue) {
            if (in_array(strtolower($headerName), ['x-forwarded-for', 'via', 'x-real-ip'])) {
                $revealingHeaders[] = "{$headerName}: {$headerValue}";
                $isElite = false;
            }
        }

        if ($isElite) {
            echo "✅ Verification: No revealing headers (X-Forwarded-For, Via) were found.\n";
        } else {
            echo "⚠️  Verification: Found revealing headers: " . implode(', ', $revealingHeaders) . "\n";
        }
    } catch (\Throwable $e) {
        echo "\n\033[31m--- TEST FAILED ---\033[0m\n";
        echo "The resilient client failed after exhausting all available proxies.\n";
        echo "Final Error: " . $e->getMessage() . "\n";
    }
});
