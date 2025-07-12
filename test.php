<?php

// Make sure to run `composer install` first
require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncSocket;
use Rcalicdan\FiberAsync\ValueObjects\Socket;

// --- A Simple Test Runner ---
$testCounter = 0;
$passedCounter = 0;
$failedTests = [];

function runTest(string $description, callable $testLogic): void
{
    global $testCounter, $passedCounter, $failedTests;
    $testCounter++;
    echo "==================================================\n";
    echo "[TEST {$testCounter}] {$description}\n";
    echo "--------------------------------------------------\n";

    try {
        // Run the async test logic within the event loop
        $result = AsyncLoop::run($testLogic);
        echo "\n\033[32m[PASS]\033[0m {$description}\n";
        $passedCounter++;
    } catch (Throwable $e) {
        echo "\n\n\033[31m[FAIL]\033[0m {$description}\n";
        echo '    REASON: '.$e->getMessage()."\n";
        echo '    IN FILE: '.$e->getFile().' ON LINE '.$e->getLine()."\n";
        $failedTests[] = $description;
    } finally {
        // Reset facades to ensure a clean state for the next test
        Async::reset();
        AsyncLoop::reset();
        AsyncSocket::reset();
        echo "==================================================\n\n";
    }
}

// --- Test Case 1: Successful Connection, Write, Read, and Close ---
runTest(
    'Full Lifecycle: Connect, Write, Read (Unencrypted)',
    Async::async(function () {
        $host = 'google.com';
        $address = "tcp://{$host}:80";
        echo "-> Connecting to {$address}...\n";
        /** @var Socket $socket */
        $socket = Async::await(AsyncSocket::connect($address));
        assert($socket instanceof Socket, 'connect() should return a Socket object.');
        echo "-> Connection successful.\n";
        $httpRequest = "GET / HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
        echo "-> Writing HTTP GET request...\n";
        $bytesWritten = Async::await($socket->write($httpRequest));
        assert($bytesWritten > 0, 'write() should return a positive number of bytes.');
        echo "-> Successfully wrote {$bytesWritten} bytes.\n";
        echo "-> Reading response...\n";
        $response = '';
        while (($chunk = Async::await($socket->read())) !== null) {
            $response .= $chunk;
        }
        echo "-> Read complete.\n";
        assert(str_contains($response, 'HTTP/1.1'), 'Response should be a valid HTTP response.');
        echo '-> Response seems valid.';
    })
);

// --- Test Case 2: Connection Timeout ---
runTest(
    'Connection Timeout',
    Async::async(function () {
        $address = 'tcp://10.255.255.1:80'; // Non-routable IP
        $timeout = 1.0;
        echo "-> Attempting to connect to {$address} with a {$timeout}s timeout...\n";
        $caughtException = null;

        try {
            Async::await(AsyncSocket::connect($address, $timeout));
        } catch (Throwable $e) {
            $caughtException = $e;
        }
        assert($caughtException !== null, 'An exception should have been thrown.');
        assert(str_contains($caughtException->getMessage(), 'timed out'), 'Exception message should indicate a timeout.');
        echo '-> Successfully caught expected timeout exception.';
    })
);

// --- Test Case 3: Connection Refused ---
runTest(
    'Connection Refused',
    Async::async(function () {
        $address = 'tcp://127.0.0.1:9'; // Port 9 is the Discard Protocol, usually closed.
        echo "-> Attempting to connect to {$address} to test refusal...\n";
        $caughtException = null;

        try {
            Async::await(AsyncSocket::connect($address, 2.0));
        } catch (Throwable $e) {
            $caughtException = $e;
        }
        assert($caughtException !== null, 'An exception should have been thrown for a refused connection.');
        $message = strtolower($caughtException->getMessage());
        assert(str_contains($message, 'connection refused') || str_contains($message, 'connection failed'), "Exception should indicate refusal. Got: {$message}");
        echo '-> Successfully caught expected connection refused exception.';
    })
);

// --- [NEW] Test Case 4: Concurrent Connections ---
runTest(
    'Concurrent Connections',
    Async::async(function () {
        $hosts = ['google.com', 'cloudflare.com', 'github.com'];
        echo '-> Starting '.count($hosts)." connections simultaneously...\n";

        $makeRequest = Async::async(function (string $host) {
            echo "  -> [{$host}] Connecting...\n";
            /** @var Socket $socket */
            $socket = Async::await(AsyncSocket::connect("tcp://{$host}:80"));
            echo "  -> [{$host}] Connected. Sending request...\n";
            $httpRequest = "HEAD / HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
            Async::await($socket->write($httpRequest));
            $response = Async::await($socket->read(1024)); // Read just the headers
            echo "  -> [{$host}] Received response.\n";
            $socket->close();

            return $response;
        });

        $promises = [];
        foreach ($hosts as $host) {
            $promises[$host] = $makeRequest($host);
        }

        $results = Async::await(Async::all($promises));

        assert(count($results) === count($hosts), 'Should receive a result for every host.');
        foreach ($hosts as $host) {
            assert(isset($results[$host]), "Result for {$host} should be present.");
            assert(str_contains($results[$host], 'HTTP/1.1'), "Response from {$host} should be a valid HTTP response.");
            echo "  -> [{$host}] Response validated.\n";
        }
        echo '-> All concurrent connections completed successfully.';
    })
);

// --- [NEW] Test Case 5: TLS/SSL Encrypted Connection ---
runTest(
    'TLS/SSL Encrypted Connection',
    Async::async(function () {
        $host = 'google.com';
        // Use tls:// stream wrapper for an encrypted connection
        $address = "tls://{$host}:443";
        echo "-> Connecting to secure address {$address}...\n";

        /** @var Socket $socket */
        $socket = Async::await(AsyncSocket::connect($address));
        assert($socket instanceof Socket, 'connect() should return a Socket object.');
        echo "-> Secure connection successful.\n";

        $httpRequest = "GET / HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
        echo "-> Writing HTTP GET request over TLS...\n";

        Async::await($socket->write($httpRequest));
        $response = '';
        while (($chunk = Async::await($socket->read())) !== null) {
            $response .= $chunk;
        }
        echo "-> Read complete over TLS.\n";
        assert(str_contains($response, 'HTTP/1.1 200 OK'), "Secure response should be '200 OK'.");
        assert(strlen($response) > 0, 'Secure response should not be empty.');
        echo '-> Secure response seems valid.';
    })
);

// --- Final Summary ---
echo "\n\n--- TEST SUMMARY ---\n";
if ($passedCounter === $testCounter) {
    echo "\033[32mAll {$testCounter} tests passed successfully!\033[0m\n";
} else {
    $failedCount = $testCounter - $passedCounter;
    echo "\033[31m{$passedCounter} passed, {$failedCount} failed.\033[0m\n";
    echo "Failed tests:\n";
    foreach ($failedTests as $testName) {
        echo "  - {$testName}\n";
    }
}
