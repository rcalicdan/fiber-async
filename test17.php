<?php

// debug_persistent_cookies.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\Cookie;
use Rcalicdan\FiberAsync\Http\FileCookieJar;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;

function printDebug($message)
{
    echo "🔍 DEBUG: $message\n";
}

function printSuccess($message)
{
    echo "✓ $message\n";
}

function printError($message)
{
    echo "✗ $message\n";
}

run(function () {
    $http = new HttpHandler;
    $cookieFile = 'debug_cookies.json';

    echo "=== Debugging Persistent Cookie Issue ===\n\n";

    // Clean up
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }

    // Test 1: Check what httpbin actually returns
    echo "1. Testing what httpbin response-headers actually returns...\n";

    $response = await($http->request()
        ->get('https://httpbin.org/response-headers?Set-Cookie=test_cookie=test_value;Path=/'));

    printDebug('Response status: '.$response->status());
    printDebug('Response headers: '.json_encode($response->getHeaders(), JSON_PRETTY_PRINT));

    $setCookieHeaders = $response->getHeader('Set-Cookie');
    printDebug('Set-Cookie headers found: '.count($setCookieHeaders));
    foreach ($setCookieHeaders as $header) {
        printDebug("Set-Cookie header: $header");
    }

    echo "\n2. Testing with FileCookieJar and httpbin response...\n";

    $jar = new FileCookieJar($cookieFile, true);

    $response2 = await($http->request()
        ->useCookieJar($jar)
        ->get('https://httpbin.org/response-headers?Set-Cookie=persistent_test=persistent_value;Path=/'));

    printDebug('Response status: '.$response2->status());
    printDebug('Cookies in jar after request: '.count($jar->getAllCookies()));

    foreach ($jar->getAllCookies() as $cookie) {
        printDebug('Cookie in jar: '.$cookie->getName().'='.$cookie->getValue().
                   ' (domain: '.($cookie->getDomain() ?? 'null').', path: '.$cookie->getPath().')');
    }

    // Force save by destroying jar
    unset($jar);

    if (file_exists($cookieFile)) {
        $content = file_get_contents($cookieFile);
        printDebug('Cookie file exists, content: '.$content);
    } else {
        printError('Cookie file was not created!');
    }

    echo "\n3. Testing manual cookie creation...\n";

    $jar3 = new FileCookieJar($cookieFile, true);

    // Manually add a cookie
    $manualCookie = new Cookie(
        'manual_test',
        'manual_value',
        time() + 3600,
        'httpbin.org',
        '/',
        false,
        false
    );

    $jar3->setCookie($manualCookie);
    printDebug('Added manual cookie to jar');
    printDebug('Cookies in jar after manual add: '.count($jar3->getAllCookies()));

    // Force save
    unset($jar3);

    if (file_exists($cookieFile)) {
        $content = file_get_contents($cookieFile);
        printDebug('Cookie file after manual add: '.$content);
    }

    echo "\n4. Testing cookie loading and sending...\n";

    $jar4 = new FileCookieJar($cookieFile, true);
    printDebug('Cookies loaded from file: '.count($jar4->getAllCookies()));

    foreach ($jar4->getAllCookies() as $cookie) {
        printDebug('Loaded cookie: '.$cookie->getName().'='.$cookie->getValue());
    }

    $response4 = await($http->request()
        ->useCookieJar($jar4)
        ->get('https://httpbin.org/cookies'));

    if ($response4->successful()) {
        $data = $response4->json();
        printDebug('Cookies sent to server: '.json_encode($data['cookies'] ?? []));
    }

    echo "\n5. Testing with working httpbin endpoint...\n";

    $cookieFile2 = 'debug_cookies_2.json';
    if (file_exists($cookieFile2)) {
        unlink($cookieFile2);
    }

    $jar5 = new FileCookieJar($cookieFile2, true);

    // Use the cookies/set endpoint which definitely works
    $response5 = await($http->request()
        ->useCookieJar($jar5)
        ->get('https://httpbin.org/cookies/set/working_test/working_value'));

    printDebug('Set cookie response status: '.$response5->status());
    printDebug('Cookies in jar after httpbin/cookies/set: '.count($jar5->getAllCookies()));

    foreach ($jar5->getAllCookies() as $cookie) {
        printDebug('Cookie from httpbin/cookies/set: '.$cookie->getName().'='.$cookie->getValue());
    }

    // Force save
    unset($jar5);

    if (file_exists($cookieFile2)) {
        $content = file_get_contents($cookieFile2);
        printDebug('Cookie file from working endpoint: '.$content);
    }

    // Test loading and using
    $jar6 = new FileCookieJar($cookieFile2, true);

    $response6 = await($http->request()
        ->useCookieJar($jar6)
        ->get('https://httpbin.org/cookies'));

    if ($response6->successful()) {
        $data = $response6->json();
        printDebug('Final test - cookies sent: '.json_encode($data['cookies'] ?? []));

        if (isset($data['cookies']['working_test'])) {
            printSuccess('SUCCESS: Persistent cookies work with proper endpoint!');
        } else {
            printError("FAILED: Even working endpoint didn't persist cookies");
        }
    }

    // Clean up
    foreach ([$cookieFile, $cookieFile2] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    echo "\n=== Debug Complete ===\n";
});
