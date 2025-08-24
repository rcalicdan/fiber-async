<?php
// example_cookie_testing.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\Testing\TestingHttpHandler;

run(function() {
    $http = new TestingHttpHandler();
    
    echo "=== Cookie Testing Examples ===\n\n";
    
    // Example 1: Basic cookie management
    echo "1. Basic Cookie Management:\n";
    
    $http->withGlobalCookieJar() // Enable automatic cookie handling
         ->cookies()->addCookie('auth_token', 'abc123', 'example.com');
    
    $http->mock('GET')
         ->url('https://example.com/api/user')
         ->expectCookies(['auth_token' => 'abc123']) // Require this cookie
         ->json(['user' => 'john', 'id' => 123])
         ->register();
    
    $response = await($http->request()
        ->get('https://example.com/api/user'));
    
    echo "Response: " . json_encode($response->json()) . "\n";
    
    // Assert the cookie was sent
    $http->assertCookieSent('auth_token');
    $http->assertCookieValue('auth_token', 'abc123');
    
    echo "✓ Cookie assertions passed!\n\n";
    
    // Example 2: Mock setting cookies
    echo "2. Mock Setting Cookies:\n";
    
    $http->mock('POST')
         ->url('https://example.com/login')
         ->setCookie('session_id', 'xyz789', '/', 'example.com', time() + 3600)
         ->setCookie('preferences', 'dark_mode=1', '/')
         ->json(['success' => true])
         ->register();
    
    $loginResponse = await($http->request()
        ->post('https://example.com/login', ['username' => 'john']));
    
    echo "Login response: " . json_encode($loginResponse->json()) . "\n";
    
    // Check if cookies were automatically stored
    $http->assertCookieExists('session_id');
    $http->assertCookieExists('preferences');
    
    echo "Cookie count: " . $http->cookies()->getCookieCount() . "\n";
    echo "✓ Cookies automatically stored!\n\n";
    
    // Example 3: Persistent cookie testing
    echo "3. Persistent Cookie Testing:\n";
    
    $cookieFile = $http->cookies()->createTempCookieFile();
    $http->withGlobalFileCookieJar($cookieFile, true);
    
    // Add some cookies
    $http->cookies()->addCookies([
        'user_id' => '12345',
        'theme' => ['value' => 'dark', 'expires' => time() + 86400],
        'language' => ['value' => 'en', 'path' => '/', 'secure' => false]
    ]);
    
    echo "Added cookies to file: $cookieFile\n";
    echo "Cookie count: " . $http->cookies()->getCookieCount() . "\n";
    
    // Simulate new session by creating new handler with same file
    $http2 = new TestingHttpHandler();
    $http2->withGlobalFileCookieJar($cookieFile, true);
    
    echo "New session cookie count: " . $http2->cookies()->getCookieCount() . "\n";
    $http2->assertCookieExists('user_id');
    $http2->assertCookieValue('theme', 'dark');
    
    echo "✓ Persistent cookies working!\n\n";
    
    // Example 4: Complex cookie scenarios
    echo "4. Complex Cookie Scenarios:\n";
    
    $http->reset(); // Clear everything
    $http->withGlobalCookieJar();
    
    // Mock a login flow that sets multiple cookies
    $http->mock('POST')
         ->url('*/login')
         ->setCookies([
             'access_token' => [
                 'value' => 'token123',
                 'expires' => time() + 3600,
                 'secure' => true,
                 'httpOnly' => true,
                 'sameSite' => 'Strict'
             ],
             'refresh_token' => [
                 'value' => 'refresh456',
                 'expires' => time() + 86400 * 7,
                 'path' => '/auth',
                 'secure' => true
             ]
         ])
         ->json(['status' => 'logged_in'])
         ->register();
    
    // Mock subsequent API call that requires cookies
    $http->mock('GET')
         ->url('*/api/profile')
         ->expectCookies(['access_token' => 'token123'])
         ->json(['profile' => 'data'])
         ->register();
    
    // Execute the flow
    $loginResp = await($http->request()->post('https://api.example.com/login'));
    $profileResp = await($http->request()->get('https://api.example.com/api/profile'));
    
    echo "Login: " . json_encode($loginResp->json()) . "\n";
    echo "Profile: " . json_encode($profileResp->json()) . "\n";
    
    // Debug cookie information
    $debugInfo = $http->cookies()->getDebugInfo();
    echo "Debug info: " . json_encode($debugInfo, JSON_PRETTY_PRINT) . "\n";
    
    echo "✓ Complex cookie flow working!\n\n";
    
    // Cleanup
    $http->reset();
    $http2->reset();
    
    echo "=== All Cookie Tests Completed ===\n";
});