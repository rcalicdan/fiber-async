<?php

// debug_test4_specific.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\FileCookieJar;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;

run(function () {
    $http = new HttpHandler;
    $cookieFile = 'debug_test4.json';

    echo "=== Debugging Test 4 Specific Issue ===\n\n";

    // Clean up
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }

    echo "1. First request - exactly like your test...\n";

    $response4a = await($http->request()
        ->withFileCookieJar($cookieFile, true)
        ->get('https://httpbin.org/response-headers?Set-Cookie=persistent_test=direct_value;Path=/'));

    echo 'Response status: '.$response4a->status()."\n";
    echo 'Response headers Set-Cookie: '.json_encode($response4a->getHeader('Set-Cookie'))."\n";

    if (file_exists($cookieFile)) {
        echo "✓ Cookie file created\n";
        $content = file_get_contents($cookieFile);
        echo 'Cookie file content: '.$content."\n";
    } else {
        echo "✗ Cookie file NOT created\n";
    }

    echo "\n2. Second request - exactly like your test...\n";

    $response4b = await($http->request()
        ->withFileCookieJar($cookieFile, true)
        ->get('https://httpbin.org/cookies'));

    if ($response4b->successful()) {
        $data = $response4b->json();
        echo 'Server received cookies: '.json_encode($data['cookies'] ?? [])."\n";

        if (isset($data['cookies']['persistent_test'])) {
            echo "✓ SUCCESS: persistent_test found!\n";
        } else {
            echo "✗ FAILED: persistent_test not found\n";
        }
    }

    echo "\n3. Let's check what's in the cookie file after both requests...\n";

    if (file_exists($cookieFile)) {
        $content = file_get_contents($cookieFile);
        echo 'Final cookie file content: '.$content."\n";

        // Let's manually load the jar and see what's in it
        $testJar = new FileCookieJar($cookieFile);
        echo 'Cookies in loaded jar: '.count($testJar->getAllCookies())."\n";
        foreach ($testJar->getAllCookies() as $cookie) {
            echo '  Cookie: '.$cookie->getName().'='.$cookie->getValue().
                 ' (domain: '.($cookie->getDomain() ?? 'NULL').', path: '.$cookie->getPath().")\n";
        }
    }

    echo "\n4. Let's test the cookie matching logic...\n";

    if (file_exists($cookieFile)) {
        $testJar = new FileCookieJar($cookieFile);

        // Test cookie matching for httpbin.org
        $matchingCookies = $testJar->getCookies('httpbin.org', '/cookies', true);
        echo "Cookies matching 'httpbin.org', '/cookies', secure=true: ".count($matchingCookies)."\n";

        $matchingCookies2 = $testJar->getCookies('httpbin.org', '/cookies', false);
        echo "Cookies matching 'httpbin.org', '/cookies', secure=false: ".count($matchingCookies2)."\n";

        $matchingCookies3 = $testJar->getCookies('httpbin.org', '/', true);
        echo "Cookies matching 'httpbin.org', '/', secure=true: ".count($matchingCookies3)."\n";

        $matchingCookies4 = $testJar->getCookies('httpbin.org', '/', false);
        echo "Cookies matching 'httpbin.org', '/', secure=false: ".count($matchingCookies4)."\n";

        // Let's also check the cookie header that would be generated
        $cookieHeader = $testJar->getCookieHeader('httpbin.org', '/cookies', true);
        echo "Cookie header for httpbin.org/cookies (HTTPS): '$cookieHeader'\n";

        $cookieHeader2 = $testJar->getCookieHeader('httpbin.org', '/cookies', false);
        echo "Cookie header for httpbin.org/cookies (HTTP): '$cookieHeader2'\n";
    }

    // Clean up
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }

    echo "\n=== Debug Complete ===\n";
});
