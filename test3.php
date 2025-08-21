<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;

Task::run(function () {
    echo "=== Testing HTTP/2 with Updated FiberAsync ===\n";
    
    try {
        // Test 1: Using Request builder with http2()
        echo "\n1. Testing with Request builder http2():\n";
        $response = await(Http::request()
            ->http2()
            ->get('https://www.google.com'));
            
        echo "Status: " . $response->status() . "\n";
        echo "Protocol (from response): " . $response->getProtocolVersion() . "\n";
        echo "Negotiated HTTP Version: " . ($response->getHttpVersion() ?? 'Not captured') . "\n";
        
        // Test 2: Using fetch with explicit HTTP/2
        echo "\n2. Testing with fetch() and explicit cURL options:\n";
        $response2 = await(Http::fetch('https://nghttp2.org/httpbin/get', [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2
        ]));
        
        echo "Status: " . $response2->status() . "\n";
        echo "Protocol (from response): " . $response2->getProtocolVersion() . "\n";
        echo "Negotiated HTTP Version: " . ($response2->getHttpVersion() ?? 'Not captured') . "\n";
        
        // Test 3: Test different servers
        echo "\n3. Testing with different servers:\n";
        
        $servers = [
            'https://http2.golang.org/reqinfo',
            'https://www.google.com',
            'https://www.cloudflare.com',
            'https://httpbin.org/get' 
        ];
        
        foreach ($servers as $server) {
            try {
                $serverResponse = await(Http::request()
                    ->http2()
                    ->timeout(10)
                    ->get($server));
                    
                echo "  $server -> " . ($serverResponse->getHttpVersion() ?? 'HTTP/' . $serverResponse->getProtocolVersion()) . " (Status: " . $serverResponse->status() . ")\n";
            } catch (Exception $e) {
                echo "  $server -> Error: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n✓ HTTP/2 negotiation testing complete!\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
});