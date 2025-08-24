<?php

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Api\Task;
use Rcalicdan\FiberAsync\Http\CookieJar;
use Rcalicdan\FiberAsync\Http\FileCookieJar; // Can use either

require 'vendor/autoload.php';

echo "====== Mocked Integration Test for Cookie Handling ======\n";

Task::run(function () {
    $handler = Http::testing();
    $handler->reset();

    // For this test, we can use an in-memory CookieJar.
    // A FileCookieJar would also work perfectly.
    $cookieJar = new CookieJar();
    
    // The session ID our mock server will create.
    $sessionId = 'session-id-' . uniqid();
    $domain = 'api.example.com';

    // =================================================================
    // Step 1: Login and Receive the Session Cookie
    // =================================================================
    echo "\n--- Step 1: Logging in to get a session cookie --- \n";

    // Mock the /login endpoint to return a Set-Cookie header.
    Http::mock('POST')
        ->url("https://{$domain}/login")
        ->respondWithStatus(200)
        ->header('Set-Cookie', "session_id={$sessionId}; Path=/; HttpOnly")
        ->json(['status' => 'logged_in'])
        ->persistent()
        ->register();

    // Create a request builder instance that will use our cookie jar.
    $client = Http::request()->useCookieJar($cookieJar);

    $response = await($client->post("https://{$domain}/login"));

    echo "   -> Login request complete. Status: " . $response->status() . "\n";

    // Assert that the cookie jar now contains our session cookie.
    $cookies = $cookieJar->getCookies($domain, '/');
    if (count($cookies) === 1 && $cookies[0]->getName() === 'session_id' && $cookies[0]->getValue() === $sessionId) {
        echo "   ✓ SUCCESS: CookieJar correctly stored the session cookie.\n";
    } else {
        echo "   ✗ FAILED: CookieJar did not store the session cookie correctly.\n";
    }

    // =================================================================
    // Step 2: Access a Protected Resource with the Cookie
    // =================================================================
    echo "\n--- Step 2: Accessing a protected resource --- \n";

    // Mock the protected endpoint. It will only succeed if the correct cookie is sent.
    Http::mock('GET')
        ->url("https://{$domain}/api/profile")
        ->withHeader('Cookie', "session_id={$sessionId}") // This matcher is the key
        ->respondWithStatus(200)
        ->persistent()
        ->json(['user_id' => 123, 'name' => 'John Doe']);
    
    // Add a fallback mock in case the cookie is NOT sent.
    Http::mock('GET')
        ->url("https://{$domain}/api/profile")
        ->respondWithStatus(401)
        ->persistent()
        ->json(['error' => 'Unauthorized']);

    echo "   -> Making request to /api/profile. The cookie should be sent automatically...\n";

    // Use the same client instance, which holds the cookie jar.
    $profileResponse = await($client->get("https://{$domain}/api/profile"));
    
    if ($profileResponse->status() === 200) {
        echo "   ✓ SUCCESS: Received 200 OK. The cookie was sent and accepted.\n";
        echo "   User Data: " . $profileResponse->body() . "\n";
    } else {
        echo "   ✗ FAILED: Did not get a 200 OK. Status: " . $profileResponse->status() . "\n";
    }

    // =================================================================
    // Step 3: Logout and Expire the Cookie
    // =================================================================
    echo "\n--- Step 3: Logging out and expiring the cookie --- \n";

    // Mock the /logout endpoint to return a Set-Cookie header with an expiry date in the past.
    $expiredCookieHeader = 'session_id=deleted; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
    Http::mock('POST')
        ->url("https://{$domain}/logout")
        ->respondWithStatus(200)
        ->header('Set-Cookie', $expiredCookieHeader)
        ->json(['status' => 'logged_out'])
        ->persistent()
        ->register();

    $logoutResponse = await($client->post("https://{$domain}/logout"));

    echo "   -> Logout request complete. Status: " . $logoutResponse->status() . "\n";
    
    // The cookie jar should now be empty after processing the expired cookie.
    $cookiesAfterLogout = $cookieJar->getCookies($domain, '/');
    if (count($cookiesAfterLogout) === 0) {
        echo "   ✓ SUCCESS: CookieJar is now empty as the session cookie was expired.\n";
    } else {
        echo "   ✗ FAILED: CookieJar still contains cookies.\n";
    }

    // Final assertion on the total number of requests made.
    $handler->assertRequestCount(1 + 2 + 1); // login + profile (2 mocks defined) + logout
});

echo "\n====== Cookie Handling Test Complete ======\n";