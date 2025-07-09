<?php

require 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Exceptions\ConnectionException;
use Rcalicdan\FiberAsync\Exceptions\TimeoutException;
use Rcalicdan\FiberAsync\Facades\Async;
use Rcalicdan\FiberAsync\Facades\AsyncLoop;
use Rcalicdan\FiberAsync\Facades\AsyncSocket;

// --- A Simple Test Runner (you can copy this from the previous test file) ---
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
        AsyncLoop::run($testLogic);
        echo "\n\033[32m[PASS]\033[0m {$description}\n";
        $passedCounter++;
    } catch (Throwable $e) {
        echo "\n\n\033[31m[FAIL]\033[0m {$description}\n";
        echo '    REASON: '.get_class($e).' - '.$e->getMessage()."\n";
        $failedTests[] = $description;
    } finally {
        Async::reset();
        AsyncLoop::reset();
        AsyncSocket::reset();
        echo "==================================================\n\n";
    }
}

// --- Test Case 1: Read Timeout on an active but idle connection ---
runTest(
    'Read Timeout',
    Async::async(function () {
        echo "-> Connecting to google.com...\n";
        $socket = Async::await(AsyncSocket::connect('tcp://google.com:80'));
        echo "-> Connected. Now attempting to read with a very short timeout...\n";
        $caughtException = null;

        try {
            // A 1ms timeout is almost guaranteed to trigger before Google responds
            Async::await($socket->read(1024, 0.001));
        } catch (TimeoutException $e) {
            $caughtException = $e;
        } finally {
            $socket->close();
        }
        assert($caughtException instanceof TimeoutException, 'A TimeoutException should have been caught.');
        echo '-> Successfully caught expected TimeoutException.';
    })
);

// --- Test Case 2: TLS Connection with Invalid Peer Name (expect failure) ---
runTest(
    'TLS Connection with Invalid Peer Name',
    Async::async(function () {
        $host = 'google.com';
        $invalidPeerName = 'example.com';
        $address = "tls://{$host}:443";
        $context = [
            'ssl' => [
                'verify_peer' => true,
                'peer_name' => $invalidPeerName, // This will cause the handshake to fail
            ],
        ];
        echo "-> Connecting to {$address} but verifying against '{$invalidPeerName}'...\n";
        echo "-> This test is expected to fail with a ConnectionException.\n";
        $caughtException = null;

        try {
            Async::await(AsyncSocket::connect($address, 10.0, $context));
        } catch (ConnectionException $e) {
            $caughtException = $e;
        }
        assert($caughtException instanceof ConnectionException, 'A ConnectionException should have been thrown.');
        assert(
            str_contains($caughtException->getMessage(), 'certificate') || str_contains($caughtException->getMessage(), 'handshake'),
            'Exception message should mention certificate/handshake failure.'
        );
        echo '-> Successfully caught expected ConnectionException due to peer name mismatch.';
    })
);

// --- Test Case 3: TLS Connection with Verification Disabled ---
runTest(
    'TLS Connection with Verification Disabled',
    Async::async(function () {
        $host = 'google.com';
        $address = "tls://{$host}:443";
        $context = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];
        echo "-> Connecting to {$address} with SSL verification disabled...\n";
        $socket = Async::await(AsyncSocket::connect($address, 10.0, $context));
        assert($socket instanceof Rcalicdan\FiberAsync\ValueObjects\Socket, 'Connection should succeed when verification is off.');
        echo "-> Connection successful. Now closing.\n";
        $socket->close();
    })
);

// --- Final Summary ---
echo "\n\n--- TEST SUMMARY ---\n";
if ($passedCounter === $testCounter) {
    echo "\033[32mAll {$testCounter} production-readiness tests passed successfully!\033[0m\n";
} else {
    $failedCount = $testCounter - $passedCounter;
    echo "\033[31m{$passedCounter} passed, {$failedCount} failed.\033[0m\n";
    echo "Failed tests:\n";
    foreach ($failedTests as $testName) {
        echo "  - {$testName}\n";
    }
}
