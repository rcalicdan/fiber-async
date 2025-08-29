<?php

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\SSE\SSEEvent;

require "vendor/autoload.php";

run(function () {
    $count = 0;
    $lastEventTime = time();
    
    $promise = http()
        ->sseReconnect(
            enabled: true,
            maxAttempts: 10,
            initialDelay: 1.0,
            maxDelay: 30.0,
            backoffMultiplier: 2.0,
            jitter: true,
            onReconnect: function (int $attempt, float $delay) {
                echo "[RECONNECT] Attempt #$attempt after {$delay}s delay\n";
            }
        )
        ->sse(
            url: "https://stream.wikimedia.org/v2/stream/recentchange",
            onEvent: function (SSEEvent $event) use (&$count, &$lastEventTime) {
                $count++;
                $lastEventTime = time();
                $data = json_decode($event->data, true);
                echo "[EVENT] #$count at " . date('H:i:s') . " - Title: {$data['title']}\n";
            },
            onError: function (string $error) {
                echo "[ERROR] " . date('H:i:s') . " - Connection error: $error\n";
            }
        );

    // Also add a timer to detect when events stop coming
    $checkTimer = setInterval(function () use (&$lastEventTime, &$count) {
        $timeSinceLastEvent = time() - $lastEventTime;
        if ($timeSinceLastEvent > 10) { // If no events for 10+ seconds
            echo "[STATUS] No events received for {$timeSinceLastEvent}s (last count: $count)\n";
        }
    }, 5000); // Check every 5 seconds

    try {
        await($promise);
    } finally {
        clearInterval($checkTimer);
    }
});