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
    // Test 1: Basic cookie setting and sending
    echo "Test 1: Setting individual cookies\n";
    $response = await(
        Http::request()
            ->cookie('session_id', '12345')
            ->cookie('user_pref', 'dark_mode')
            ->get('https://httpbin.org/cookies')
    );
    
    $data = $response->json();
    echo "Cookies sent: " . json_encode($data['cookies']) . "\n\n";

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
    echo "Multiple cookies sent: " . json_encode($data['cookies']) . "\n\n";

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
    $response1->applyCookiesToJar($cookieJar);
    echo "Cookies in jar: " . count($cookieJar->getAllCookies()) . "\n";
    
    // Second request should automatically send stored cookies
    $response2 = await(
        Http::request()
            ->withCookieJar($cookieJar)
            ->get('https://httpbin.org/cookies')
    );
    
    $data = $response2->json();
    echo "Cookies automatically sent: " . json_encode($data['cookies']) . "\n\n";

    // Test 5: Cookie expiration and matching
    echo "Test 5: Cookie expiration and domain matching\n";
    
    // Create cookies with different attributes
    $sessionCookie = new Cookie('session', 'temp123');
    $persistentCookie = new Cookie(
        'persistent', 
        'value456', 
        time() + 3600, // expires in 1 hour
        '.example.com',
        '/',
        true, // secure
        true  // httpOnly
    );
    $expiredCookie = new Cookie('expired', 'old', time() - 1); // already expired
    
    $cookieJar->setCookie($sessionCookie);
    $cookieJar->setCookie($persistentCookie);
    $cookieJar->setCookie($expiredCookie);
    
    echo "Total cookies before cleanup: " . count($cookieJar->getAllCookies()) . "\n";
    $cookieJar->clearExpired();
    echo "Total cookies after cleanup: " . count($cookieJar->getAllCookies()) . "\n";
    
    // Test domain matching
    $matchingCookies = $cookieJar->getCookies('sub.example.com', '/');
    echo "Cookies matching 'sub.example.com': " . count($matchingCookies) . "\n\n";
});

// Advanced Cookie Tests
echo "=== Advanced Cookie Tests ===\n";

Task::run(function () {
    // Test 6: File-based persistent cookie jar
    echo "Test 6: File-based persistent cookie storage\n";
    
    $cookieFile = sys_get_temp_dir() . '/test_cookies.json';
    $fileCookieJar = new FileCookieJar($cookieFile, true); // store session cookies
    
    // Set some cookies
    $response = await(
        Http::request()
            ->withCookieJar($fileCookieJar)
            ->get('https://httpbin.org/cookies/set/persistent_test/file_value')
    );
    
    $response->applyCookiesToJar($fileCookieJar);
    echo "Cookies stored to file: {$cookieFile}\n";
    
    // Create new jar from same file to test persistence
    $newJar = new FileCookieJar($cookieFile);
    $persistedCookies = $newJar->getAllCookies();
    echo "Cookies loaded from file: " . count($persistedCookies) . "\n";
    
    // Clean up
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
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
    }
    echo "\n";

    // Test 8: Session management simulation
    echo "Test 8: Session management simulation\n";
    
    $sessionJar = new CookieJar();
    
    // Simulate login
    echo "Simulating login...\n";
    $loginResponse = await(
        Http::request()
            ->withCookieJar($sessionJar)
            ->json(['username' => 'testuser', 'password' => 'testpass'])
            ->post('https://httpbin.org/cookies/set/session_token/logged_in_12345')
    );
    
    $loginResponse->applyCookiesToJar($sessionJar);
    echo "Login cookies stored\n";
    
    // Simulate authenticated request
    echo "Making authenticated request...\n";
    $authResponse = await(
        Http::request()
            ->withCookieJar($sessionJar)
            ->get('https://httpbin.org/cookies')
    );
    
    $authData = $authResponse->json();
    echo "Authenticated request cookies: " . json_encode($authData['cookies']) . "\n";
    
    // Test cookie header generation
    $cookieHeader = $sessionJar->getCookieHeader('httpbin.org', '/cookies');
    echo "Generated Cookie header: {$cookieHeader}\n\n";

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
    
    // Add 1000 cookies
    for ($i = 0; $i < 1000; $i++) {
        $perfJar->setCookie(new Cookie("cookie_{$i}", "value_{$i}", time() + 3600));
    }
    
    $addTime = microtime(true) - $startTime;
    echo "Time to add 1000 cookies: " . number_format($addTime * 1000, 2) . "ms\n";
    
    $startTime = microtime(true);
    $matchingCookies = $perfJar->getCookies('example.com', '/');
    $matchTime = microtime(true) - $startTime;
    
    echo "Time to match cookies: " . number_format($matchTime * 1000, 2) . "ms\n";
    echo "Matching cookies found: " . count($matchingCookies) . "\n";
});

echo "=== All Cookie Tests Completed ===\n";