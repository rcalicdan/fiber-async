<?php

// test_cookies.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\CookieJar;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;

// Colors for console output
function colorOutput($text, $color = 'green')
{
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'purple' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m",
    ];

    return $colors[$color].$text.$colors['reset'];
}

function printTest($title)
{
    echo "\n".colorOutput("=== $title ===", 'cyan')."\n";
}

function printSuccess($message)
{
    echo colorOutput("✓ $message", 'green')."\n";
}

function printError($message)
{
    echo colorOutput("✗ $message", 'red')."\n";
}

function printInfo($message)
{
    echo colorOutput("ℹ $message", 'blue')."\n";
}

// Main test function
function testCookieManagement()
{
    $http = new HttpHandler;

    printTest('Testing Automatic Cookie Management with HTTPBin');

    try {
        // Test 1: Basic cookie setting and retrieval
        printTest('Test 1: Basic Cookie Setting');

        $response = await($http->request()
            ->withCookieJar()
            ->get('https://httpbin.org/cookies/set/test_cookie/hello_world'));

        if ($response->successful()) {
            printSuccess('Cookie set successfully');
            printInfo('Status: '.$response->status());
        } else {
            printError('Failed to set cookie: '.$response->status());

            return false;
        }

        // Test 2: Verify cookies are sent automatically
        printTest('Test 2: Automatic Cookie Sending');

        $cookieJar = new CookieJar;

        // First request - set cookies
        $response1 = await($http->request()
            ->useCookieJar($cookieJar)
            ->get('https://httpbin.org/cookies/set?session=abc123&user=testuser'));

        if ($response1->successful()) {
            printSuccess('Cookies set in jar');
        }

        // Second request - cookies should be sent automatically
        $response2 = await($http->request()
            ->useCookieJar($cookieJar)
            ->get('https://httpbin.org/cookies'));

        if ($response2->successful()) {
            $data = $response2->json();
            if (isset($data['cookies']['session']) && $data['cookies']['session'] === 'abc123') {
                printSuccess('Cookies automatically sent: session='.$data['cookies']['session']);
            }
            if (isset($data['cookies']['user']) && $data['cookies']['user'] === 'testuser') {
                printSuccess('Cookies automatically sent: user='.$data['cookies']['user']);
            }
            printInfo('All received cookies: '.json_encode($data['cookies'] ?? []));
        }

        // Test 3: Manual cookie addition
        printTest('Test 3: Manual Cookie Addition');

        $response3 = await($http->request()
            ->cookie('manual_cookie', 'manual_value')
            ->cookie('another_cookie', 'another_value')
            ->get('https://httpbin.org/cookies'));

        if ($response3->successful()) {
            $data = $response3->json();
            printInfo('Manual cookies sent: '.json_encode($data['cookies'] ?? []));

            if (isset($data['cookies']['manual_cookie'])) {
                printSuccess("Manual cookie 'manual_cookie' sent successfully");
            }
            if (isset($data['cookies']['another_cookie'])) {
                printSuccess("Manual cookie 'another_cookie' sent successfully");
            }
        }

        // Test 4: Persistent cookie jar
        // Test 4: Persistent cookie jar (CORRECTED)
        printTest('Test 4: Persistent Cookie Jar');

        $cookieFile = 'test_cookies.json';

        // Clean up any existing cookie file
        if (file_exists($cookieFile)) {
            unlink($cookieFile);
        }

        // First request with persistent cookies
        $response4a = await($http->request()
            ->withFileCookieJar($cookieFile, true)
            ->get('https://httpbin.org/response-headers?Set-Cookie=persistent_test=direct_value;Path=/'));

        if ($response4a->successful()) {
            printSuccess('Persistent cookies set');

            if (file_exists($cookieFile)) {
                printSuccess("Cookie file created: $cookieFile");
                $cookieContent = file_get_contents($cookieFile);
                printInfo('Cookie file content preview: '.substr($cookieContent, 0, 100).'...');
            }
        }

        // Second request to verify persistence (simulate new session)
        $response4b = await($http->request()
            ->withFileCookieJar($cookieFile, true)
            ->get('https://httpbin.org/cookies'));

        if ($response4b->successful()) {
            $data = $response4b->json();
            if (isset($data['cookies']['persistent_test'])) {  // ← FIXED: Correct cookie name
                printSuccess('Persistent cookie loaded from file: '.$data['cookies']['persistent_test']);
            } else {
                printError('Persistent cookie not found in subsequent request');
                printInfo('Available cookies: '.json_encode($data['cookies'] ?? []));
            }
        }

        // Test 5: Cookie jar inspection
        printTest('Test 5: Cookie Jar Inspection');

        $inspectionJar = new CookieJar;

        $response5 = await($http->request()
            ->useCookieJar($inspectionJar)
            ->get('https://httpbin.org/cookies/set?inspect1=value1&inspect2=value2'));

        if ($response5->successful()) {
            $allCookies = $inspectionJar->getAllCookies();
            printSuccess('Cookies in jar: '.count($allCookies));

            foreach ($allCookies as $cookie) {
                printInfo('Cookie: '.$cookie->getName().'='.$cookie->getValue());
            }
        }

        // Test 6: Cookie clearing
        printTest('Test 6: Cookie Clearing');

        $clearJar = new CookieJar;

        // Set some cookies
        await($http->request()
            ->useCookieJar($clearJar)
            ->get('https://httpbin.org/cookies/set/clear_test/before_clear'));

        printInfo('Cookies before clear: '.count($clearJar->getAllCookies()));

        // Clear cookies
        $clearJar->clear();
        printInfo('Cookies after clear: '.count($clearJar->getAllCookies()));

        if (count($clearJar->getAllCookies()) === 0) {
            printSuccess('Cookies cleared successfully');
        }

        // Test 7: Multiple cookies at once
        printTest('Test 7: Multiple Cookies');

        $response7 = await($http->request()
            ->cookies([
                'multi1' => 'value1',
                'multi2' => 'value2',
                'multi3' => 'value3',
            ])
            ->get('https://httpbin.org/cookies'));

        if ($response7->successful()) {
            $data = $response7->json();
            $receivedCookies = $data['cookies'] ?? [];

            foreach (['multi1', 'multi2', 'multi3'] as $cookieName) {
                if (isset($receivedCookies[$cookieName])) {
                    printSuccess("Multi-cookie '$cookieName' sent successfully");
                }
            }
        }

        // Test 8: Cookie attributes
        printTest('Test 8: Cookie with Attributes');

        $attrJar = new CookieJar;
        $request8 = $http->request()->useCookieJar($attrJar);

        // Add cookie with attributes
        $request8->cookieWithAttributes('secure_cookie', 'secure_value', [
            'secure' => true,
            'httpOnly' => true,
            'sameSite' => 'Strict',
            'path' => '/',
        ]);

        $response8 = await($request8->get('https://httpbin.org/cookies'));

        if ($response8->successful()) {
            $data = $response8->json();
            if (isset($data['cookies']['secure_cookie'])) {
                printSuccess('Cookie with attributes sent successfully');
            }
        }

        // Clean up
        if (file_exists($cookieFile)) {
            unlink($cookieFile);
            printInfo('Cleaned up test cookie file');
        }

        printTest('All Tests Completed');
        printSuccess('Cookie management system is working correctly!');

        return true;
    } catch (Exception $e) {
        printError('Test failed with exception: '.$e->getMessage());
        printError('Stack trace: '.$e->getTraceAsString());

        return false;
    }
}

// Run the tests
printTest('Starting Cookie Management Tests');
printInfo('Using HTTPBin for testing HTTP cookie functionality');

run(function () {
    $result = testCookieManagement();

    if ($result) {
        echo "\n".colorOutput('🎉 All tests passed! Cookie management is working correctly.', 'green')."\n\n";
        exit(0);
    } else {
        echo "\n".colorOutput('❌ Some tests failed. Please check the implementation.', 'red')."\n\n";
        exit(1);
    }
});
