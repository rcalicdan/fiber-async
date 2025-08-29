<?php
// File: test_sse_reconnection_manual.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\SSE\SSEEvent;
use Rcalicdan\FiberAsync\Http\SSE\SSEReconnectConfig;


// Ensure your corrected framework files are in place.

// ======================================================================
// The Manual SSE Reconnection Test
// ======================================================================

run(function () {
    echo "======================================================================\n";
    echo " FiberAsync Manual SSE Reconnection Test\n";
    echo "======================================================================\n";

    // --- State Tracking ---
    $eventCount = 0;
    $errorCount = 0;
    $reconnectsTriggered = false;

    try {
        $url = 'https://stream.wikimedia.org/v2/stream/recentchange';
        $httpHandler = new HttpHandler();

        $onEvent = function (SSEEvent $event) use (&$eventCount, &$reconnectsTriggered) {

            $eventCount++;
            $data = json_decode($event->data ?? '', true);
            $title = $data['title'] ?? 'N/A';
            if ($reconnectsTriggered && $eventCount > 0) {
                echo "\033[32m✅ RECONNECTION SUCCESSFUL! New event received.\033[0m\n";
                // After success, we can just let it stream.
            }
            echo "  [Event #{$eventCount}] Title: {$title}\n";
        };

        $onError = function (string $error) use (&$errorCount) {
            $errorCount++;
            // This will fire when the network drop is detected by cURL.
            echo "\033[31m[CONNECTION ERROR DETECTED]\033[0m Message: {$error}\n";
        };

        $reconnectConfig = new SSEReconnectConfig(
            maxAttempts: 20, // Allow many attempts for a manual test
            initialDelay: 2.0, // Start with a 2-second delay
            maxDelay: 10.0,
            backoffMultiplier: 1.2,
            onReconnect: function(int $attempt, float $delay) use (&$reconnectsTriggered) {
                $reconnectsTriggered = true;
                echo "\033[33m[RECONNECTING...]\033[0m Attempt #{$attempt}. Trying again in " . round($delay, 1) . "s.\n";
            }
        );

        $options = [CURLOPT_USERAGENT => 'FiberAsync-Manual-Reconnect-Test/1.0'];

        echo "--- Step 1: Connecting to the stream... ---\n";
        $ssePromise = $httpHandler->sse($url, $options, $onEvent, $onError, $reconnectConfig);
        
        $result = await($ssePromise);
        echo "✅ Connection established. The stream is now live.\n\n";

        echo "\033[36m********************* ACTION REQUIRED *********************\n";
        echo "**\n";
        echo "**  1. Please DISCONNECT your computer's internet connection NOW.\n";
        echo "**     (Turn off Wi-Fi or unplug the network cable).\n";
        echo "**\n";
        echo "**  2. You should see [CONNECTION ERROR] and [RECONNECTING...] messages.\n";
        echo "**\n";
        echo "**  3. After a few seconds, RECONNECT your internet.\n";
        echo "**\n";
        echo "**  4. The script should detect the recovery and start receiving events again.\n";
        echo "**\n";
        echo "**  Press Ctrl+C to stop the test at any time.\n";
        echo "**\n";
        echo "***********************************************************\033[0m\n\n";
        
        // This await will suspend the main fiber indefinitely. The test now runs
        // in the background until the user stops it or an unrecoverable error occurs.
        await($ssePromise);

    } catch (\Throwable $e) {
        // This block will run if all reconnection attempts fail, or on Ctrl+C.
        if (str_contains($e->getMessage(), 'Promise cancelled')) {
             echo "\n\nTest stopped by user or graceful shutdown.\n";
        } else {
            echo "\n\033[31m--- UNRECOVERABLE ERROR ---\033[0m\n";
            echo "The SSE connection failed permanently: " . $e->getMessage() . "\n";
        }
    } finally {
        // This summary will be printed when the user presses Ctrl+C.
        echo "\n======================================================================\n";
        echo " Test Finished: Final Assessment\n";
        echo "======================================================================\n";
        echo " - Total Events Received: {$eventCount}\n";
        echo " - Connection Errors Detected: {$errorCount}\n";
        
        if ($reconnectsTriggered) {
            echo " - Reconnection Logic: \033[32mSUCCESSFULLY TRIGGERED\033[0m\n";
            echo " - Verdict: \033[32mPASSED! The client demonstrated true resilience against a real network failure.\033[0m\n";
        } else {
            echo " - Reconnection Logic: \033[31mNOT TRIGGERED\033[0m\n";
            echo " - Verdict: \033[33mFAILED. A real network failure was not simulated during the test.\033[0m\n";
        }
    }
});