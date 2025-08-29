<?php

use Rcalicdan\FiberAsync\Http\Handlers\HttpHandler;
use Rcalicdan\FiberAsync\Http\SSE\SSEEvent;
use Rcalicdan\FiberAsync\Http\SSE\SSEReconnectConfig;

require "vendor/autoload.php";

run(function () {
    $count = 0;
    $url = "https://stream.wikimedia.org/v2/stream/recentchange";
    
    echo "Connecting to: $url\n";

    $http = new HttpHandler();
    
    $promise = $http->sse(
        url: $url,
        options: [                              // Changed from curlOptions to options
            CURLOPT_USERAGENT => 'Simple-Test/1.0',
        ],
        onEvent: function (SSEEvent $event) use (&$count) {
            $count++;
            $data = json_decode($event->data, true);
            echo "Event: $count, Title: {$data['title']}\n";
        },
        onError: function (string $error) {
            echo "Error: $error\n";
        },
        reconnectConfig: new SSEReconnectConfig(true, 5)
    );

    try {
        await($promise);
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
});