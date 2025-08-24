<?php
// test_persistent_cookies.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\HttpHandler;
use Rcalicdan\FiberAsync\Http\FileCookieJar;
use Rcalicdan\FiberAsync\Api\Async;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler as HandlersHttpHandler;

run(function() {
    $http = new HandlersHttpHandler();
    $cookieFile = 'test_persistent_cookies.json';
    
    // Clean up any existing cookie file
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
    
    echo "=== Testing Persistent Cookie Management ===\n\n";
    
    // Test 1: Set cookies with direct Set-Cookie header (no redirect)
    echo "1. Setting cookies directly (avoiding redirect issues)...\n";
    
    $jar1 = new FileCookieJar($cookieFile, true); // Store session cookies too
    
    // Use a different httpbin endpoint that doesn't redirect
    $response1 = await($http->request()
        ->cookieJarWith($jar1)
        ->get('https://httpbin.org/response-headers?Set-Cookie=persistent_test=direct_value;Path=/'));
    
    echo "Response status: " . $response1->status() . "\n";
    echo "Cookies in jar after first request: " . count($jar1->getAllCookies()) . "\n";
    
    // Manual cookie addition to ensure something is saved
    $jar1->setCookie(new \Rcalicdan\FiberAsync\Http\Cookie(
        'manual_persistent',
        'manual_value',
        time() + 3600, // Expires in 1 hour
        null,
        '/',
        false,
        false
    ));
    
    echo "Cookies after manual addition: " . count($jar1->getAllCookies()) . "\n";
    
    if (file_exists($cookieFile)) {
        echo "✓ Cookie file created\n";
        $content = file_get_contents($cookieFile);
        echo "Cookie file content:\n" . $content . "\n";
    }
    
    unset($jar1); // Force save by destroying the object
    
    // Test 2: Load cookies from file (simulate new session)
    echo "\n2. Loading cookies from file (new session)...\n";
    
    $jar2 = new FileCookieJar($cookieFile, true);
    echo "Cookies loaded from file: " . count($jar2->getAllCookies()) . "\n";
    
    foreach ($jar2->getAllCookies() as $cookie) {
        echo "  - " . $cookie->getName() . "=" . $cookie->getValue() . "\n";
    }
    
    // Test 3: Use loaded cookies in a request
    echo "\n3. Testing if loaded cookies are sent...\n";
    
    $response3 = await($http->request()
        ->cookieJarWith($jar2)
        ->get('https://httpbin.org/cookies')); // Fixed: removed extra spaces
    
    if ($response3->successful()) {
        $data = $response3->json();
        echo "Cookies sent to server: " . json_encode($data['cookies'] ?? [], JSON_PRETTY_PRINT) . "\n";
        
        if (isset($data['cookies']['manual_persistent'])) {
            echo "✓ SUCCESS: Persistent cookie was loaded and sent!\n";
        } else {
            echo "✗ FAILED: Persistent cookie was not sent\n";
        }
    } else {
        echo "✗ FAILED: Request failed with status: " . $response3->status() . "\n";
    }
    
    // Clean up
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
        echo "\nCleaned up test file.\n";
    }
});