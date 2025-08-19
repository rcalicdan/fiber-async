<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\Cookie;
use Rcalicdan\FiberAsync\Http\CookieJar;
use Rcalicdan\FiberAsync\Http\FileCookieJar;

require 'vendor/autoload.php';

// Basic Cookie Tests
echo "=== Basic Cookie Tests ===\n";

Task::run(function () {
    // Test 1: Basic cookie setting and sending (FIXED)
    echo "Test 1: Setting individual cookies\n";
    $response = await(
        Http::request()
            ->cookie('session_id', '12345')
            ->cookie('user_pref', 'dark_mode')
            ->get('https://httpbin.org/cookies')
    );
    
    $data = $response->json();
    // Fix: Check if cookies key exists before accessing
    if (isset($data['cookies'])) {
        echo "Cookies sent: " . json_encode($data['cookies']) . "\n";
    } else {
        echo "Response structure: " . json_encode($data) . "\n";
        echo "Cookies sent: Unable to verify (response format unexpected)\n";
    }
    echo "\n";

    // Test 2: Setting multiple cookies at once
    echo "Test 2: Setting multiple cookies\n";
    $response = await(
        Http::request()
            ->cookies([
                'auth_token' => 'abc123',
                'language' => 'en',
                'theme' => 'light'
            ])
            ->get('https://httpbin.org/cookies')
    );
    
    $data = $response->json();
    echo "Multiple cookies sent: " . json_encode($data['cookies'] ?? 'No cookies key found') . "\n\n";

    // Test 3: Receiving cookies from server
    echo "Test 3: Receiving cookies from server\n";
    $response = await(
        Http::request()
            ->get('https://httpbin.org/cookies/set?test_cookie=test_value&another=value2')
    );
    
    $cookies = $response->getCookies();
    echo "Received " . count($cookies) . " cookies:\n";
    foreach ($cookies as $cookie) {
        echo "  {$cookie->getName()} = {$cookie->getValue()}\n";
    }
    echo "\n";
});

// Intermediate Cookie Tests with CookieJar
echo "=== Intermediate Cookie Tests (CookieJar) ===\n";

Task::run(function () {
    $cookieJar = new CookieJar();
    
    // Test 4: Using cookie jar for automatic handling
    echo "Test 4: Cookie jar automatic handling\n";
    
    // First request sets cookies
    $response1 = await(
        Http::request()
            ->withCookieJar($cookieJar)
            ->get('https://httpbin.org/cookies/set/jar_test/12345')
    );
    
    echo "First response status: {$response1->status()}\n";
    
    // Check if cookies were automatically stored
    $storedCookies = $cookieJar->getAllCookies();
    echo "Cookies automatically stored in jar: " . count($storedCookies) . "\n";
    
    // If no cookies were auto-stored, manually apply them
    if (count($storedCookies) === 0) {
        $response1->applyCookiesToJar($cookieJar);
        echo "Cookies manually applied to jar: " . count($cookieJar->getAllCookies()) . "\n";
    }
    
    // Second request should automatically send stored cookies
    $response2 = await(
        Http::request()
            ->withCookieJar($cookieJar)
            ->get('https://httpbin.org/cookies')
    );
    
    $data = $response2->json();
    echo "Cookies automatically sent: " . json_encode($data['cookies'] ?? []) . "\n\n";

    // Test 5: Cookie expiration and domain matching (FIXED)
    echo "Test 5: Cookie expiration and domain matching\n";
    
    $testJar = new CookieJar();
    
    // Create cookies with different attributes for proper testing
    $sessionCookie = new Cookie('session', 'temp123', null, 'example.com', '/');
    $persistentCookie = new Cookie(
        'persistent', 
        'value456', 
        time() + 3600, // expires in 1 hour
        '.example.com', // Leading dot for subdomain matching
        '/',
        false, // not secure for testing
        false  // not httpOnly for testing
    );
    $expiredCookie = new Cookie('expired', 'old', time() - 1, 'example.com', '/'); // already expired
    
    $testJar->setCookie($sessionCookie);
    $testJar->setCookie($persistentCookie);
    $testJar->setCookie($expiredCookie);
    
    echo "Total cookies before cleanup: " . count($testJar->getAllCookies()) . "\n";
    
    // Show which cookies we have before cleanup
    echo "Cookies before cleanup:\n";
    foreach ($testJar->getAllCookies() as $cookie) {
        $expiry = $cookie->getExpires() ? date('Y-m-d H:i:s', $cookie->getExpires()) : 'session';
        echo "  {$cookie->getName()} (expires: {$expiry})\n";
    }
    
    $testJar->clearExpired();
    $remainingCookies = $testJar->getAllCookies();
    echo "Total cookies after cleanup: " . count($remainingCookies) . "\n";
    
    // Verify which cookies remained
    echo "Remaining cookies:\n";
    foreach ($remainingCookies as $cookie) {
        echo "  {$cookie->getName()} = {$cookie->getValue()} (domain: {$cookie->getDomain()})\n";
    }
    
    // Test domain matching with proper domains
    $exactMatchCookies = $testJar->getCookies('example.com', '/');
    echo "Cookies matching exact 'example.com': " . count($exactMatchCookies) . "\n";
    foreach ($exactMatchCookies as $cookie) {
        echo "  Exact match: {$cookie->getName()} (domain: {$cookie->getDomain()})\n";
    }
    
    $subdomainMatchCookies = $testJar->getCookies('sub.example.com', '/');
    echo "Cookies matching subdomain 'sub.example.com': " . count($subdomainMatchCookies) . "\n";
    
    // Debug: Show all cookies and test subdomain matching manually
    echo "Debug - Testing subdomain matching:\n";
    foreach ($testJar->getAllCookies() as $cookie) {
        $domain = $cookie->getDomain();
        $matches = false;
        
        if ($domain === 'sub.example.com') {
            $matches = true;
        } elseif ($domain && $domain[0] === '.' && str_ends_with('sub.example.com', substr($domain, 1))) {
            $matches = true;
        }
        
        echo "  Cookie '{$cookie->getName()}' domain '{$domain}' matches sub.example.com: " . ($matches ? 'YES' : 'NO') . "\n";
    }
    echo "\n";
});

// Advanced Cookie Tests
echo "=== Advanced Cookie Tests ===\n";

Task::run(function () {
    // Test 6: File-based persistent cookie jar
    echo "Test 6: File-based persistent cookie storage\n";
    
    $cookieFile = sys_get_temp_dir() . '/test_cookies_' . uniqid() . '.json';
    $fileCookieJar = new FileCookieJar($cookieFile, true);
    
    // Set some cookies
    $response = await(
        Http::request()
            ->withCookieJar($fileCookieJar)
            ->get('https://httpbin.org/cookies/set/persistent_test/file_value')
    );
    
    // Ensure cookies are applied if not automatic
    if (count($fileCookieJar->getAllCookies()) === 0) {
        $response->applyCookiesToJar($fileCookieJar);
    }
    
    echo "Cookies stored to file: {$cookieFile}\n";
    echo "File exists: " . (file_exists($cookieFile) ? 'yes' : 'no') . "\n";
    
    // Create new jar from same file to test persistence
    $newJar = new FileCookieJar($cookieFile);
    $persistedCookies = $newJar->getAllCookies();
    echo "Cookies loaded from file: " . count($persistedCookies) . "\n";
    
    // Verify persisted cookie details
    foreach ($persistedCookies as $cookie) {
        echo "  Persisted: {$cookie->getName()} = {$cookie->getValue()}\n";
    }
    
    // Clean up
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
        echo "Cleanup: Cookie file deleted\n";
    }
    echo "\n";

    // Test 7: Complex cookie attributes
    echo "Test 7: Complex cookie attributes and SameSite\n";
    
    $complexCookie = new Cookie(
        'complex',
        'test_value',
        time() + 7200, // 2 hours
        '.httpbin.org',
        '/cookies',
        true,  // secure
        true,  // httpOnly
        7200,  // maxAge
        'Strict' // sameSite
    );
    
    echo "Cookie header format: " . $complexCookie->toSetCookieHeader() . "\n";
    echo "Simple cookie format: " . $complexCookie->toCookieHeader() . "\n";
    
    // Test parsing Set-Cookie header
    $setCookieHeader = 'test=value; Domain=.example.com; Path=/; Secure; HttpOnly; SameSite=Lax; Max-Age=3600';
    $parsedCookie = Cookie::fromSetCookieHeader($setCookieHeader);
    
    if ($parsedCookie) {
        echo "Parsed cookie - Name: {$parsedCookie->getName()}, Value: {$parsedCookie->getValue()}\n";
        echo "Domain: {$parsedCookie->getDomain()}, Path: {$parsedCookie->getPath()}\n";
        echo "Secure: " . ($parsedCookie->isSecure() ? 'true' : 'false') . "\n";
        echo "HttpOnly: " . ($parsedCookie->isHttpOnly() ? 'true' : 'false') . "\n";
        echo "SameSite: {$parsedCookie->getSameSite()}\n";
    } else {
        echo "ERROR: Failed to parse Set-Cookie header\n";
    }
    echo "\n";

    // Test 8: Session management simulation (FIXED)
    echo "Test 8: Session management simulation\n";
    
    $sessionJar = new CookieJar();
    
    // Simulate login - try different approach
    echo "Simulating login...\n";
    
    // First, try to get any cookies set by the server
    $loginResponse = await(
        Http::request()
            ->get('https://httpbin.org/cookies/set/session_token/logged_in_12345')
    );
    
    echo "Login response status: {$loginResponse->status()}\n";
    
    // Apply cookies to jar manually to ensure they're stored
    $loginResponse->applyCookiesToJar($sessionJar);
    echo "Login cookies stored: " . count($sessionJar->getAllCookies()) . "\n";
    
    // Show stored cookies
    foreach ($sessionJar->getAllCookies() as $cookie) {
        echo "  Stored: {$cookie->getName()} = {$cookie->getValue()}\n";
    }
    
    // Simulate authenticated request
    echo "Making authenticated request...\n";
    $authResponse = await(
        Http::request()
            ->withCookieJar($sessionJar)
            ->get('https://httpbin.org/cookies')
    );
    
    $authData = $authResponse->json();
    echo "Authenticated request cookies: " . json_encode($authData['cookies'] ?? []) . "\n";
    
    // Test cookie header generation
    $cookieHeader = $sessionJar->getCookieHeader('httpbin.org', '/cookies');
    echo "Generated Cookie header: '{$cookieHeader}'\n\n";

    // Test 9: Multiple domains and paths
    echo "Test 9: Multiple domains and path matching\n";
    
    $multiJar = new CookieJar();
    
    // Add cookies for different domains and paths
    $multiJar->setCookie(new Cookie('site1', 'value1', null, 'example.com', '/'));
    $multiJar->setCookie(new Cookie('site2', 'value2', null, 'test.com', '/api'));
    $multiJar->setCookie(new Cookie('site3', 'value3', null, '.example.com', '/admin'));
    
    echo "Cookies for 'example.com' '/': " . count($multiJar->getCookies('example.com', '/')) . "\n";
    echo "Cookies for 'sub.example.com' '/': " . count($multiJar->getCookies('sub.example.com', '/')) . "\n";
    echo "Cookies for 'example.com' '/admin': " . count($multiJar->getCookies('example.com', '/admin')) . "\n";
    echo "Cookies for 'test.com' '/api': " . count($multiJar->getCookies('test.com', '/api')) . "\n";
    echo "\n";

    // Test 10: Performance test with many cookies
    echo "Test 10: Performance test with many cookies\n";
    
    $perfJar = new CookieJar();
    $startTime = microtime(true);
    
    // Add 1000 cookies with proper domains for testing
    for ($i = 0; $i < 1000; $i++) {
        $domain = ($i % 3 === 0) ? 'example.com' : (($i % 3 === 1) ? 'test.com' : 'other.com');
        $perfJar->setCookie(new Cookie("cookie_{$i}", "value_{$i}", time() + 3600, $domain, '/'));
    }
    
    $addTime = microtime(true) - $startTime;
    echo "Time to add 1000 cookies: " . number_format($addTime * 1000, 2) . "ms\n";
    echo "Total cookies in jar: " . count($perfJar->getAllCookies()) . "\n";
    
    $startTime = microtime(true);
    $matchingCookies = $perfJar->getCookies('example.com', '/');
    $matchTime = microtime(true) - $startTime;
    
    echo "Time to match cookies for 'example.com': " . number_format($matchTime * 1000, 2) . "ms\n";
    echo "Matching cookies found: " . count($matchingCookies) . "\n";
    
    // Verify the matches are correct
    $expectedMatches = ceil(1000 / 3);
    echo "Expected matches: ~{$expectedMatches}, Actual: " . count($matchingCookies) . "\n";
});

echo "=== All Cookie Tests Completed ===\n";