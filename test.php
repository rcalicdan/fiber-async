<?php
require_once 'vendor/autoload.php';

use Rcalicdan\FiberAsync\EventLoop\EventLoop;
use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\SSE\SSEEvent;
use Rcalicdan\FiberAsync\Http\SSE\SSEReconnectConfig;

run(function () {
    echo "Starting simple SSE test...\n";

    $url = 'https://stream.wikimedia.org/v2/stream/recentchange';
    $eventCount = 0;
    $maxEvents = 5;
    $shutdownInitiated = false;

    $onEvent = function (SSEEvent $event) use (&$eventCount, $maxEvents, &$shutdownInitiated, &$sseRequestId) {
        if ($shutdownInitiated) {
            return;
        }

        $eventCount++;

        $title = 'N/A';
        if ($event->data) {
            $data = json_decode($event->data, true);
            if (is_array($data) && isset($data['title'])) {
                $title = $data['title'];
            }
        }

        echo "Event #{$eventCount}: {$title}\n";

        if ($eventCount >= $maxEvents) {
            $shutdownInitiated = true;
            echo "Received {$maxEvents} events, canceling request and shutting down...\n";
            EventLoop::getInstance()->stop();
        }
    };

    $reconnectConfig = new SSEReconnectConfig(
        enabled: false,
        maxAttempts: 0,
        initialDelay: 1.0,
        maxDelay: 5.0
    );

    $options = [CURLOPT_USERAGENT => 'Simple-Test/1.0'];

    echo "Connecting to SSE stream...\n";

    $httpHandler = new HttpHandler();

    try {
        $result = await($httpHandler->sse($url, $options, $onEvent, null, $reconnectConfig));
        echo "SSE completed with result: " . json_encode($result) . "\n";
    } catch (Exception $e) {
        echo "SSE failed: " . $e->getMessage() . "\n";
    }

    echo "Final event count: {$eventCount}\n";
});
