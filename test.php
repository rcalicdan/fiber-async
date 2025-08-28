<?php
// File: test_sse_reconnection_final_definitive.php

require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\SSE\SSEEvent;
use Rcalicdan\FiberAsync\Http\SSE\SSEReconnectConfig;
use Rcalicdan\FiberAsync\Promise\Interfaces\CancellablePromiseInterface;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use Rcalicdan\FiberAsync\Promise\Promise;


// Ensure your corrected SSEHandler, PromiseInterface, Promise, and CancellablePromise files are in place.

// ======================================================================
// The SSE Handler Verification Test (Final Robust Version)
// ======================================================================

run(function () {
    echo "======================================================================\n";
    echo " FiberAsync Automated SSE Reconnection Test (Definitive)\n";
    echo "======================================================================\n";

    // --- Configuration & State ---
    $url = 'https://stream.wikimedia.org/v2/stream/recentchange';
    $eventsBeforeDisconnect = 5;
    $eventsAfterReconnect = 5;
    
    $eventCount = 0;
    $reconnectsTriggered = false;
    $phase = 'initial';
    $initialEventsReceived = 0;
    $isClosing = false;
    
    /** @var ?CancellablePromiseInterface $sseConnectionPromise */
    $sseConnectionPromise = null;
    /** @var ?Promise $waitPromise */
    $waitPromise = new Promise();

    try {
        echo "This test will:\n";

        $onEvent = function (SSEEvent $event) use (
            $eventsBeforeDisconnect, 
            $eventsAfterReconnect, 
            $waitPromise,
            &$eventCount,
            &$phase,
            &$initialEventsReceived,
            &$isClosing,
            &$sseConnectionPromise
        ) {
            if ($isClosing) return;
            
            $eventCount++;
            
            if ($phase === 'recovering') {
                echo "\033[32m✅ RECONNECTION SUCCESSFUL!\033[0m Now validating the new stream...\n";
                $phase = 'validating';
            }

            echo "  [Event #{$eventCount}] Title: " . (($data['title'] ?? 'N/A')) . "\n";

            if ($phase === 'initial' && $eventCount >= $eventsBeforeDisconnect) {
                echo "\n\033[31m--- SIMULATING NETWORK FAILURE (Closing Connection) ---\033[0m\n";
                $phase = 'recovering';
                $initialEventsReceived = $eventCount;
                if ($sseConnectionPromise) {
                    $sseConnectionPromise->cancel();
                }
            }

            if ($phase === 'validating' && ($eventCount - $initialEventsReceived) >= $eventsAfterReconnect) {
                $isClosing = true;
                echo "\n--- Test complete. Received events after reconnect. Signaling shutdown... ---\n";
                if ($waitPromise->isPending()) {
                    $waitPromise->resolve(null);
                }
            }
        };
        
        $reconnectConfig = new SSEReconnectConfig(
            onReconnect: function (int $attempt, float $delay) use (&$reconnectsTriggered) {
                $reconnectsTriggered = true;
                echo "\033[33m[RECONNECTING...]\033[0m Attempt #{$attempt}. Trying again in " . round($delay, 1) . "s.\n";
            }
        );

        $options = [CURLOPT_USERAGENT => 'FiberAsync-Reconnect-Test/1.0'];

        echo "--- Step 1: Connecting to the stream... ---\n";
        
        $httpHandler = new HttpHandler();
        $sseConnectionPromise = $httpHandler->sse($url, $options, $onEvent, null, $reconnectConfig);
        
        try {
            await($sseConnectionPromise);
            echo "✅ Initial connection established. Waiting for events...\n";
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Promise cancelled')) {
                throw $e;
            }
        }
        
        await($waitPromise);

    
        echo "--- Final cleanup: Closing any remaining connections... ---\n";
        if ($sseConnectionPromise && $sseConnectionPromise->isPending()) {
            $sseConnectionPromise->cancel();
        }

    } catch (\Throwable $e) {
        echo "\n\033[31m--- UNRECOVERABLE ERROR ---\033[0m\n";
        echo "The SSE connection failed permanently: " . $e->getMessage() . "\n";
    } finally {
        // This `finally` block will now only execute AFTER the `await($waitPromise)`
        // has completed, so the state variables will be correct.
        echo "\n======================================================================\n";
        echo " Test Finished: Final Assessment\n";
        echo "======================================================================\n";
        echo " - Total Events Received: {$eventCount}\n";
        if ($reconnectsTriggered) {
            echo " - Reconnection Logic: \033[32mSUCCESSFULLY TRIGGERED\033[0m\n";
            if ($eventCount >= ($eventsBeforeDisconnect + $eventsAfterReconnect)) {
                echo " - Verdict: \033[32mPASSED! The client successfully recovered and received new events.\033[0m\n";
            } else {
                echo " - Verdict: \033[33mPARTIAL PASS. Reconnection was triggered but not all validation events were received.\033[0m\n";
            }
        } else {
            echo " - Verdict: \033[31mFAILED. The reconnection logic was not triggered.\033[0m\n";
        }
    }
});